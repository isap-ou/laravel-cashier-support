<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The host-app entry point: Cashier::fake() with no driver installed, driven through Billable.
 */
class CashierFakeTest extends TestCase
{
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
}
