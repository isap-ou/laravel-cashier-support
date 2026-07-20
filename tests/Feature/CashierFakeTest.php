<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeSubscription;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The host-app entry point: Cashier::fake() with no driver installed, driven through Billable.
 */
class CashierFakeTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_host_app_can_fake_and_assert_a_subscription_was_created(): void
    {
        $fake = Cashier::fake();

        (new User)->newSubscription('default', 'price_monthly')->create();

        $fake->assertSubscriptionCreated();
        $fake->assertSubscriptionCreated(fn ($s) => $s->type === 'default');
    }

    public function test_the_no_argument_default_supports_every_capability(): void
    {
        $fake = Cashier::fake();

        foreach (Capability::cases() as $capability) {
            $this->assertTrue($fake->supports($capability), "fake() should support {$capability->value} by default");
        }
    }

    public function test_fake_is_the_active_driver_so_billable_charges_reach_it(): void
    {
        $fake = Cashier::fake();

        (new User)->charge(1000, 'pm_x');

        $fake->assertCharged(fn ($p) => $p->amount === 1000);
    }

    public function test_an_explicit_capability_list_constrains_what_the_fake_answers(): void
    {
        Cashier::fake([Capability::Charges]);

        $this->expectException(UnsupportedOperationException::class);

        (new User)->newSubscription('default', 'price_monthly')->create();
    }

    public function test_a_constrained_fake_still_serves_the_capabilities_it_was_given(): void
    {
        $fake = Cashier::fake([Capability::Charges]);

        (new User)->charge(500, 'pm_x');

        $fake->assertCharged();
    }

    public function test_calling_fake_twice_makes_the_latest_instance_the_active_driver(): void
    {
        Cashier::fake([Capability::Charges]);
        $second = Cashier::fake([Capability::Charges]);

        (new User)->charge(700, 'pm_x');

        // Reaches the SECOND fake only because fake() forgets the already-resolved driver.
        $second->assertCharged(fn ($p) => $p->amount === 700);
    }

    public function test_the_model_backed_billable_methods_work_against_the_fake(): void
    {
        // The half of Billable fake() used to break. It registered the gateway and swapped the
        // default driver but bound no MODELS — and Models\* are abstract, so every read path
        // through them raised InvalidConfigurationException: charge() worked, subscribed() did
        // not. That contradicted fake()'s own docblock ("test its billing code with no real
        // driver installed"), and the exception's advice was unfollowable — the fake has no
        // service provider to call useModels() from.
        Cashier::fake();

        $user = User::create(['name' => 'Ada']);

        // Reads first: these are what threw, and they must answer rather than explode for a
        // billable that has never subscribed.
        $this->assertFalse($user->subscribed('default'));
        $this->assertNull($user->subscription('default'));
        $this->assertSame(0, $user->subscriptions()->count());

        // ...and the write path lands in a real row that the reads then see.
        $user->subscriptions()->create([
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_fake',
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertTrue($user->subscribed('default'));
        $this->assertInstanceOf(FakeSubscription::class, $user->subscription('default'));
    }

    public function test_faking_does_not_take_away_a_real_drivers_model_bindings(): void
    {
        // The regression half. Bindings are per-driver, so calling fake() inside a suite that
        // already had a working driver used to leave the app worse than before it faked.
        Cashier::useModels('other', ['subscription' => FakeSubscription::class]);

        Cashier::fake();

        $this->assertSame(FakeSubscription::class, Cashier::subscriptionModel('other'));
        $this->assertSame(FakeSubscription::class, Cashier::subscriptionModel('fake'));
    }
}
