<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use InvalidArgumentException;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\PriceTaxedUser;
use Isapp\CashierSupport\Tests\Fixtures\TaxedUser;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * Taxes and subscription trials were the only two capabilities the package
 * never gated: an app that declared tax rates on a gateway without tax support
 * got silence, and its configuration was discarded. Unsupported must mean
 * "throw", never "ignore" — everywhere.
 */
class CapabilityGatingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    /**
     * @param  array<int, Capability>  $capabilities
     */
    private function driverSupporting(array $capabilities): void
    {
        Cashier::extend('fake', fn () => new FakeGateway($capabilities));
    }

    private const BASE = [
        Capability::Charges,
        Capability::Subscriptions,
        Capability::CheckoutPrices,
    ];

    public function test_declared_tax_rates_throw_when_the_driver_has_no_tax_support(): void
    {
        $this->driverSupporting(self::BASE);

        $this->expectException(UnsupportedOperationException::class);
        (new TaxedUser)->newSubscription('default', 'price_1');
    }

    // A swap also re-applies the owner's tax rates, so a gateway without Taxes must refuse it too.
    // That path is now on Models\Subscription (#39) and is proven in SubscriptionMutationTest.

    public function test_price_tax_rates_alone_are_enough_to_throw(): void
    {
        $this->driverSupporting(self::BASE);

        $this->expectException(UnsupportedOperationException::class);
        (new PriceTaxedUser)->newSubscription('default', 'price_1');
    }

    public function test_a_charge_and_a_checkout_do_not_consume_tax_rates(): void
    {
        // Following Stripe Cashier, tax rates are read only when building or
        // swapping a subscription. Guarding a one-off charge or a checkout
        // would turn a supported, tax-free operation into an outage.
        $this->driverSupporting(self::BASE);

        $this->assertSame(1500, (new TaxedUser)->charge(1500, 'pm_visa')->amount);
        $this->assertSame('cs_fake', (new TaxedUser)->checkout('price_1')->id());
    }

    public function test_declared_tax_rates_are_honoured_when_the_driver_supports_taxes(): void
    {
        $this->driverSupporting([...self::BASE, Capability::Taxes]);

        $subscription = (new TaxedUser)->newSubscription('default', 'price_1')->create();

        $this->assertSame('sub_fake', $subscription->id);
    }

    public function test_a_billable_without_tax_rates_is_unaffected(): void
    {
        // The guard must not tax (sic) the ordinary path.
        $this->driverSupporting(self::BASE);

        $this->assertSame(1500, (new User)->charge(1500, 'pm_visa')->amount);
        $this->assertSame('sub_fake', (new User)->newSubscription('default', 'price_1')->create()->id);
    }

    public function test_a_trial_throws_when_the_driver_has_no_trial_support(): void
    {
        $this->driverSupporting(self::BASE);

        $this->expectException(UnsupportedOperationException::class);
        (new User)->newSubscription('default', 'price_1')->trialDays(14);
    }

    public function test_a_trial_until_throws_when_the_driver_has_no_trial_support(): void
    {
        $this->driverSupporting(self::BASE);

        $this->expectException(UnsupportedOperationException::class);
        (new User)->newSubscription('default', 'price_1')->trialUntil(now()->addDays(14));
    }

    public function test_a_trial_reaches_the_providers_builder_when_trials_are_supported(): void
    {
        $gateway = new FakeGateway([...self::BASE, Capability::SubscriptionTrials]);
        Cashier::extend('fake', fn () => $gateway);

        $subscription = (new User)->newSubscription('default', 'price_1')
            ->trialDays(14)
            ->create();

        $this->assertSame('sub_fake', $subscription->id);
        // The guard must forward, not merely not-throw.
        $this->assertSame(14, $gateway->lastBuilder?->trialDays);
    }

    public function test_a_negative_trial_is_refused(): void
    {
        // The contract has promised this since it was written — Contracts\SubscriptionBuilder:25
        // declares "@throws InvalidArgumentException When the number of days is negative" — and
        // nothing threw it. Third instance of the defect .claude/rules/exceptions.md was written
        // about, after charge() and ->quantity(0): a declared guard that exists only in the
        // docblock sends the caller's own typo to the gateway, where it comes back a 4xx and
        // arrives as a *billing* failure the app is invited to catch and swallow.
        $gateway = new FakeGateway([...self::BASE, Capability::SubscriptionTrials]);
        Cashier::extend('fake', fn () => $gateway);

        try {
            (new User)->newSubscription('default', 'price_1')->trialDays(-1);
            $this->fail('Expected a negative trial to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('-1', $e->getMessage());
        }

        $this->assertNull($gateway->lastBuilder?->trialDays, 'Nothing may reach the driver\'s builder.');
    }

    public function test_a_trial_of_zero_days_is_legal_and_reaches_the_builder(): void
    {
        // Where this parts company with its neighbour ->quantity(0), deliberately. Zero seats is
        // a typo — there is no such subscription. Zero trial days is an ordinary answer, and the
        // shape that produces it is ordinary too: trialDays(max(0, $daysLeftOnTheOldPlan)).
        // Refusing it would turn "this customer has no trial left" into an exception the app has
        // to pre-empt with an if, which is the guard doing the caller's arithmetic for it.
        $gateway = new FakeGateway([...self::BASE, Capability::SubscriptionTrials]);
        Cashier::extend('fake', fn () => $gateway);

        (new User)->newSubscription('default', 'price_1')->trialDays(0)->create();

        $this->assertSame(0, $gateway->lastBuilder?->trialDays);
    }

    public function test_the_capability_gate_outranks_the_trial_guard(): void
    {
        // Same order as quantity, for the same reason: a gateway with no trials at all must say
        // THAT, not quibble with the number — otherwise an app fixing the "invalid trial" it was
        // told about reaches the real answer only on the second try.
        $this->driverSupporting(self::BASE);

        $this->expectException(UnsupportedOperationException::class);

        (new User)->newSubscription('default', 'price_1')->trialDays(-1);
    }

    public function test_the_guard_forwards_every_other_builder_call(): void
    {
        $gateway = new FakeGateway([...self::BASE, Capability::SubscriptionQuantity, Capability::SubscriptionMetadata]);
        Cashier::extend('fake', fn () => $gateway);

        $subscription = (new User)->newSubscription('default', 'price_1')
            ->quantity(3)
            ->withMetadata(['team' => 'ada'])
            ->create('pm_visa');

        $this->assertSame('sub_fake', $subscription->id);
        $this->assertSame(3, $gateway->lastBuilder?->quantity);
        $this->assertSame(['team' => 'ada'], $gateway->lastBuilder?->metadata);
        $this->assertSame('pm_visa', $gateway->lastBuilder?->paymentMethod);
    }
}
