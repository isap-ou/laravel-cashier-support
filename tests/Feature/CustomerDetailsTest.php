<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\BaseGateway;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteCustomer;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\MinimalGateway;
use Isapp\CashierSupport\Tests\Fixtures\RenamedUser;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * #36: a customer could be created and never corrected.
 *
 * Two separate holes, and they fail differently, so they are tested differently.
 *
 * The first is that a model had no way to say where its own name lives, so the only route from
 * a User to a gateway was an untyped `$options` bag the app hand-assembled on every call — and
 * a driver that wanted a name without being handed one had to reach into the app's model and
 * guess an attribute. `cashierName()`/`cashierEmail()` are that seam; the tests here prove it
 * is load-bearing rather than decorative, which is exactly what an overridable hook fails at
 * quietly if nobody reads it.
 *
 * The second is that nothing pushed a change. The acceptance criterion of the issue is one
 * test — change an email, sync, assert the gateway heard it — and the rest of this class
 * defends the edges around it that a green suite would otherwise not notice: that a refusal
 * names `customers.update` and not `customers`, and that update does not quietly overwrite the
 * fields the caller did not mention.
 */
class CustomerDetailsTest extends TestCase
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
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    /**
     * A gateway with the full customer surface. Individual tests re-extend 'fake' when they
     * need a narrower one — that narrowing is the point of several of them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fake([Capability::Customers, Capability::CustomersUpdate]);
        Cashier::useModels('fake', ['customer' => ConcreteCustomer::class]);
    }

    /**
     * @param  array<int, Capability>  $capabilities
     */
    private function fake(array $capabilities): FakeGateway
    {
        $gateway = new FakeGateway($capabilities);

        // Captured by value: Manager::extend() rebinds a non-static closure to itself.
        Cashier::extend('fake', fn (): FakeGateway => $gateway);

        return $gateway;
    }

    public function test_the_two_capabilities_come_apart_on_a_real_gateway(): void
    {
        // The load-bearing claim of the whole design, and it needs BaseGateway to be tested at
        // all: FakeGateway answers supports() from a hand-passed list, so it cannot notice the
        // methods() map at all. Only a BaseGateway subclass reads support off the code, which
        // is where folding updateCustomer into Customers => [...] does its damage — silently
        // demoting every driver that has customers but has not written an update yet.
        $gateway = new class extends BaseGateway
        {
            protected function declaredCapabilities(): array
            {
                return [];
            }

            public function createCustomer(Model $billable, CustomerDetails $details): Customer
            {
                return new Customer(id: 'cus_x');
            }

            public function asCustomer(Model $billable): Customer
            {
                return new Customer(id: 'cus_x');
            }
        };

        $this->assertTrue($gateway->supports(Capability::Customers), 'Create and read are written; Customers must hold. Folding updateCustomer into this capability is what breaks it.');
        $this->assertFalse($gateway->supports(Capability::CustomersUpdate), 'The update was never written; it must not be claimed.');
    }

    public function test_the_inherited_refusal_names_the_update_not_the_customer(): void
    {
        // The other half FakeGateway cannot reach: the concern's gate throws before a driver's
        // method is ever called, so the default in Defaults\RefusesCustomers is only reachable
        // by calling the gateway directly — which is exactly what an app doing a direct provider
        // call does, and what a driver mixing in the defaults inherits.
        $gateway = new MinimalGateway;

        try {
            $gateway->updateCustomer(new User(['id' => 1]), new CustomerDetails(email: 'x@example.com'));
            $this->fail('Expected the default update to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::CustomersUpdate, $e->capability, 'Refusing with Customers would tell an app that has customers that it has none.');
        }
    }

    public function test_a_changed_email_reaches_the_gateway(): void
    {
        // The issue's acceptance criterion, verbatim: "Changing a user's email and calling
        // syncCustomerDetails() updates the record at the gateway."
        $gateway = $this->fake([Capability::Customers, Capability::CustomersUpdate]);
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $user->email = 'ada@lovelace.dev';
        $user->syncCustomerDetails();

        $this->assertSame('ada@lovelace.dev', $gateway->lastCustomerDetails?->email);
        $this->assertSame('Ada', $gateway->lastCustomerDetails?->name);
    }

    public function test_a_gateway_that_cannot_update_says_so_by_the_right_name(): void
    {
        // The distinction this whole capability exists for. Refusing with `customers` would tell
        // an app that has customers that it has none — true of nothing, and unactionable.
        $this->fake([Capability::Customers]);

        try {
            (new User)->updateCustomer(['email' => 'x@example.com']);
            $this->fail('Expected the update to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::CustomersUpdate, $e->capability);
        }
    }

    public function test_create_still_works_on_a_gateway_that_cannot_update(): void
    {
        // A gateway with customers it can never correct is a real gateway — Paddle is one
        // (vendor/laravel/cashier-paddle has no customer update at all). The two capabilities
        // must come apart cleanly, or that gateway is inexpressible.
        $gateway = $this->fake([Capability::Customers]);

        $customer = (new User(['name' => 'Ada']))->createAsCustomer();

        $this->assertSame('cus_fake', $customer->id);
        $this->assertSame('Ada', $gateway->lastCustomerDetails?->name);
    }

    public function test_create_fills_in_what_the_model_knows(): void
    {
        $gateway = $this->fake([Capability::Customers]);

        (new User(['name' => 'Ada', 'email' => 'ada@example.com']))->createAsCustomer();

        $this->assertSame('Ada', $gateway->lastCustomerDetails?->name);
        $this->assertSame('ada@example.com', $gateway->lastCustomerDetails?->email);
    }

    public function test_an_explicit_option_beats_the_hook(): void
    {
        // Precedence, not merging: an app that names a field means it. Both references resolve
        // it the same way (Stripe ManagesCustomer.php:72-94, Paddle :21-25).
        $gateway = $this->fake([Capability::Customers]);

        (new User(['name' => 'Ada', 'email' => 'ada@example.com']))
            ->createAsCustomer(['name' => 'Ada Lovelace']);

        $this->assertSame('Ada Lovelace', $gateway->lastCustomerDetails?->name);
        $this->assertSame('ada@example.com', $gateway->lastCustomerDetails?->email, 'An unnamed field still comes from the hook.');
    }

    public function test_update_sends_only_what_was_asked_for(): void
    {
        // The anti-clobber rule. If update auto-filled from the hooks like create does, an app
        // correcting one field would silently push whatever else the model happened to hold
        // over whatever the gateway happened to have. Create can fill in blanks because there
        // is no prior state; update cannot.
        $gateway = $this->fake([Capability::Customers, Capability::CustomersUpdate]);

        (new User(['name' => 'Ada', 'email' => 'ada@example.com']))
            ->updateCustomer(['email' => 'ada@lovelace.dev']);

        $this->assertSame('ada@lovelace.dev', $gateway->lastCustomerDetails?->email);
        $this->assertNull($gateway->lastCustomerDetails?->name, 'An unmentioned field must stay untouched, not be refilled from the model.');
    }

    public function test_the_hook_is_what_the_gateway_hears_not_the_column(): void
    {
        // The seam proven load-bearing: RenamedUser keeps its name in `full_name` and overrides
        // the hook. Nothing else in the stack changes, and no driver learns a column name.
        $gateway = $this->fake([Capability::Customers]);

        (new RenamedUser(['full_name' => 'Ada Lovelace']))->createAsCustomer();

        $this->assertSame('Ada Lovelace', $gateway->lastCustomerDetails?->name);
    }

    public function test_a_model_whose_name_is_not_a_string_reports_no_name(): void
    {
        // Not "does not crash" — Eloquent already returns null for an ABSENT attribute, so that
        // assertion would pass with the guard deleted and prove nothing. What is asserted is the
        // declared type holding for an attribute that is present and of the wrong type:
        // Model::__get() returns mixed, and returning this int from a ?string method TypeErrors
        // under strict_types without the narrowing in cashierName().
        $gateway = $this->fake([Capability::Customers]);

        (new User(['name' => 42]))->createAsCustomer();

        // Asserted first and deliberately: every assertion below is assertNull, and `?->` on a
        // null gateway record yields null too — so without this the test would pass just as
        // happily if createAsCustomer had never reached the gateway at all.
        $this->assertNotNull($gateway->lastCustomerDetails, 'The gateway must actually have been called.');
        $this->assertNull($gateway->lastCustomerDetails->name);
        $this->assertNull($gateway->lastCustomerDetails->email, 'A model with no email attribute at all reports none.');
    }

    public function test_an_unknown_option_stays_in_the_named_escape_hatch(): void
    {
        // A gateway-specific field is not support's to understand — but it is support's to not
        // lose. It rides in `options`, declared as the hatch, rather than being dropped on the
        // floor or promoted to a typed field this package would then have to justify.
        $gateway = $this->fake([Capability::Customers]);

        (new User(['name' => 'Ada']))->createAsCustomer(['phone' => '+3531234567']);

        $this->assertSame(['phone' => '+3531234567'], $gateway->lastCustomerDetails?->options);
        $this->assertSame('Ada', $gateway->lastCustomerDetails?->name, 'The typed fields are still lifted out of the bag.');
    }

    public function test_a_typed_field_never_also_arrives_in_the_escape_hatch(): void
    {
        // The invariant a driver depends on: `name` is in exactly one place. The first draft
        // broke it — a rejected non-string was left in $options while the hook filled the typed
        // field, so a driver merging the hatch into its request body sent `name` twice and let
        // array-merge order pick. Now the malformed argument raises instead (DtoTest covers the
        // throw); this asserts the invariant from the concern's side for every valid input.
        $gateway = $this->fake([Capability::Customers]);

        (new User(['name' => 'Ada', 'email' => 'ada@example.com']))
            ->createAsCustomer(['name' => 'Ada Lovelace', 'phone' => '+3531234567']);

        $this->assertNotNull($gateway->lastCustomerDetails);
        $this->assertSame('Ada Lovelace', $gateway->lastCustomerDetails->name);
        $this->assertArrayNotHasKey('name', $gateway->lastCustomerDetails->options, 'A field the DTO types must be consumed, not also left in the bag.');
        $this->assertArrayNotHasKey('email', $gateway->lastCustomerDetails->options);
    }

    public function test_an_explicitly_null_option_falls_back_to_the_hook_and_leaves_no_trace(): void
    {
        // ['name' => null] means "not specified" — the DTO's own contract — so the hook fills it
        // and the key does not survive into the hatch alongside the value it lost to.
        $gateway = $this->fake([Capability::Customers]);

        (new User(['name' => 'Ada']))->createAsCustomer(['name' => null]);

        $this->assertNotNull($gateway->lastCustomerDetails);
        $this->assertSame('Ada', $gateway->lastCustomerDetails->name);
        $this->assertSame([], $gateway->lastCustomerDetails->options);
    }

    public function test_a_malformed_name_is_the_callers_bug_not_a_billing_failure(): void
    {
        // exceptions.md: a malformed argument raises SPL's InvalidArgumentException and is meant
        // to be fixed. Dressing it as a CashierException would invite an app to catch — and
        // swallow — its own bug.
        $this->fake([Capability::Customers]);

        $this->expectException(InvalidArgumentException::class);

        (new User(['name' => 'Ada']))->createAsCustomer(['name' => ['Ada', 'Lovelace']]);
    }

    public function test_update_or_create_creates_when_there_is_no_customer_yet(): void
    {
        $gateway = $this->fake([Capability::Customers]);
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $user->updateOrCreateCustomer();

        // Reached create, not update: create fills from the hooks, update would have sent nulls.
        $this->assertSame('Ada', $gateway->lastCustomerDetails?->name);
    }

    public function test_update_or_create_updates_once_there_is_one(): void
    {
        $gateway = $this->fake([Capability::Customers, Capability::CustomersUpdate]);
        $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        ConcreteCustomer::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'provider' => 'fake',
            'provider_id' => 'cus_existing',
        ]);

        $user->updateOrCreateCustomer(['email' => 'ada@lovelace.dev']);

        // Reached update, not create: a name would have been filled in by create.
        $this->assertSame('ada@lovelace.dev', $gateway->lastCustomerDetails?->email);
        $this->assertNull($gateway->lastCustomerDetails?->name);
    }
}
