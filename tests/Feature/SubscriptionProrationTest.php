<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\Proration;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * Proration is a per-call intent (Enums\Proration) gated at the same boundary as the swap timing.
 * The gate is asymmetric: Prorate is the ungated baseline both references default to, so an
 * unadorned swap or quantity change never newly refuses; only NoProrate names a capability, so a
 * gateway that can only ever prorate refuses it by name rather than silently prorating anyway.
 *
 * See docs/specs/subscription-proration.md (#53).
 */
class SubscriptionProrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    /**
     * @param  array<int, Capability>  $capabilities
     */
    private function driverSupporting(array $capabilities): FakeGateway
    {
        $gateway = new FakeGateway($capabilities);

        Cashier::extend('fake', fn (): FakeGateway => $gateway);

        return $gateway;
    }

    public function test_no_prorate_reaches_the_gateway_on_swap_when_declared(): void
    {
        $gateway = $this->driverSupporting([
            Capability::Subscriptions,
            Capability::SubscriptionSwapImmediate,
            Capability::SubscriptionNoProration,
        ]);

        Cashier::provider('fake')->swapSubscription(new User(['id' => 1]), 'default', 'price_x', SwapTiming::Immediate, Proration::NoProrate);

        $this->assertSame(Proration::NoProrate, $gateway->lastSwapProration);
    }

    public function test_swap_refuses_no_prorate_when_undeclared_yet_still_prorates(): void
    {
        // Swap itself is supported; only the "do not prorate" intent is not.
        $gateway = $this->driverSupporting([
            Capability::Subscriptions,
            Capability::SubscriptionSwapImmediate,
        ]);
        $user = new User(['id' => 1]);

        try {
            Cashier::provider('fake')->swapSubscription($user, 'default', 'price_x', SwapTiming::Immediate, Proration::NoProrate);
            $this->fail('Expected the swap to refuse NoProrate.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SubscriptionNoProration, $e->capability);
        }

        // The ungated default still goes through on the very same gateway.
        Cashier::provider('fake')->swapSubscription($user, 'default', 'price_x', SwapTiming::Immediate, Proration::Prorate);
        $this->assertSame(Proration::Prorate, $gateway->lastSwapProration);
    }

    public function test_no_prorate_reaches_the_gateway_on_quantity_when_declared(): void
    {
        $gateway = $this->driverSupporting([
            Capability::Subscriptions,
            Capability::SubscriptionQuantityUpdate,
            Capability::SubscriptionNoProration,
        ]);

        Cashier::provider('fake')->updateSubscriptionQuantity(new User(['id' => 1]), 'default', 3, 'price_x', Proration::NoProrate);

        $this->assertSame(Proration::NoProrate, $gateway->lastQuantityProration);
    }

    public function test_quantity_refuses_no_prorate_when_undeclared_yet_still_prorates(): void
    {
        $gateway = $this->driverSupporting([
            Capability::Subscriptions,
            Capability::SubscriptionQuantityUpdate,
        ]);
        $user = new User(['id' => 1]);

        try {
            Cashier::provider('fake')->updateSubscriptionQuantity($user, 'default', 3, 'price_x', Proration::NoProrate);
            $this->fail('Expected the quantity update to refuse NoProrate.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SubscriptionNoProration, $e->capability);
        }

        Cashier::provider('fake')->updateSubscriptionQuantity($user, 'default', 3, 'price_x', Proration::Prorate);
        $this->assertSame(Proration::Prorate, $gateway->lastQuantityProration);
    }
}
