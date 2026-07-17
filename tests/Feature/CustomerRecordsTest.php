<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\ManagesCustomerRecords;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteCustomer;
use Isapp\CashierSupport\Tests\Fixtures\Team;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The customer identity a gateway assigns to a billable.
 *
 * It used to live as a provider-named column on the app's users table, which
 * forbade two things structurally: a second driver (a second column), and a
 * second billable type — a reverse lookup by customer id could only ever search
 * one class.
 */
class CustomerRecordsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // A second billable type — the thing a flat column could never serve.
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::extend('fake', fn () => new FakeGateway([Capability::Customers]));
        Cashier::useModels('fake', ['customer' => ConcreteCustomer::class]);
    }

    /**
     * A driver writes and reads customer records through this trait.
     */
    private function driver(): object
    {
        return new class
        {
            use ManagesCustomerRecords;

            public function driverName(): string
            {
                return 'fake';
            }

            public function write(Model $billable, string $id): void
            {
                $this->persistCustomerId($billable, $id, 'Ada', 'ada@example.com');
            }

            public function idFor(Model $billable): string
            {
                return $this->customerIdFor($billable);
            }

            public function owner(string $id): ?Model
            {
                return $this->resolveOwnerByCustomerId($id);
            }
        };
    }

    public function test_a_billable_without_a_customer_record_has_no_id(): void
    {
        $user = User::query()->create(['name' => 'Ada']);

        $this->assertFalse($user->hasCustomerId());
        $this->assertNull($user->customerId());
    }

    public function test_a_persisted_customer_id_is_readable_provider_neutrally(): void
    {
        $user = User::query()->create(['name' => 'Ada']);

        $this->driver()->write($user, 'cus_1');

        $this->assertTrue($user->hasCustomerId());
        $this->assertSame('cus_1', $user->customerId());
    }

    public function test_the_reverse_lookup_finds_any_billable_type(): void
    {
        // This is the whole point. A flat users.provider_customer_id column
        // could only ever resolve one class; an order webhook for a Team would
        // have found nothing.
        $user = User::query()->create(['name' => 'Ada']);
        $team = Team::query()->create(['name' => 'Acme']);

        $driver = $this->driver();
        $driver->write($user, 'cus_user');
        $driver->write($team, 'cus_team');

        $this->assertTrue($driver->owner('cus_user')?->is($user));
        $this->assertTrue($driver->owner('cus_team')?->is($team));
        $this->assertNull($driver->owner('cus_unknown'));
    }

    public function test_a_customer_id_is_scoped_to_its_provider(): void
    {
        $user = User::query()->create(['name' => 'Ada']);

        ConcreteCustomer::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'provider' => 'other',
            'provider_id' => 'cus_other',
        ]);

        // The record exists, but it belongs to a different gateway.
        $this->assertFalse($user->hasCustomerId());
        $this->assertNull($this->driver()->owner('cus_other'));
    }

    public function test_persisting_twice_updates_rather_than_duplicates(): void
    {
        $user = User::query()->create(['name' => 'Ada']);

        $driver = $this->driver();
        $driver->write($user, 'cus_1');
        $driver->write($user, 'cus_2');

        $this->assertSame(1, ConcreteCustomer::query()->count());
        $this->assertSame('cus_2', $user->customerId());
    }

    public function test_asking_for_a_missing_customer_id_fails_loudly(): void
    {
        $user = User::query()->create(['name' => 'Ada']);

        $this->expectException(CustomerNotFoundException::class);
        $this->driver()->idFor($user);
    }

    public function test_a_driver_that_stores_no_customers_answers_no_rather_than_exploding(): void
    {
        // A driver that never writes customer records is a legitimate driver.
        // Asking whether a billable is a customer must not require it to
        // register a model it never uses — the read API would otherwise impose
        // the very coupling the write side was kept out of support to avoid.
        Cashier::extend('slotless', fn () => new FakeGateway([Capability::Customers]));
        config()->set('cashier-support.default', 'slotless');

        $user = User::query()->create(['name' => 'Ada']);

        $this->assertFalse($user->hasCustomerId());
        $this->assertNull($user->customerId());
    }

    public function test_the_customer_record_can_be_eager_loaded(): void
    {
        // It replaced a column that was already hydrated on the row; without a
        // relation, a list of billables would issue one SELECT per row.
        $user = User::query()->create(['name' => 'Ada']);
        $this->driver()->write($user, 'cus_1');

        $loaded = User::query()->with('cashierCustomer')->findOrFail($user->getKey());

        $this->assertTrue($loaded->relationLoaded('cashierCustomer'));
        $this->assertSame('cus_1', $loaded->customerId());
    }

    public function test_an_unsaved_billable_cannot_be_recorded_as_a_customer(): void
    {
        // owner_id is NOT NULL: without this guard the insert would fail with a
        // raw QueryException, after the customer already exists at the gateway.
        $this->expectException(InvalidConfigurationException::class);
        $this->driver()->write(new User, 'cus_1');
    }

    public function test_create_or_get_customer_does_not_create_twice(): void
    {
        $user = User::query()->create(['name' => 'Ada']);

        $first = $user->createOrGetCustomer();
        $this->driver()->write($user, $first->id);

        $second = $user->createOrGetCustomer();

        $this->assertSame($first->id, $second->id);
    }
}
