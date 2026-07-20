<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use InvalidArgumentException;
use Isapp\CashierSupport\Builders\GuardedSubscriptionBuilder;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\GuardedProvider;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The one boundary every billing operation passes through. Cashier::provider() wraps each driver
 * in this guard, so the capability check is not a concern's or a model's to remember.
 *
 * The per-group gating is also exercised through the concerns (charge, invoice, quantity, …), which
 * now delegate here — this class proves the guard itself: that it gates an unsupported operation,
 * delegates a supported one, forwards the reads, and passes a webhook through ungated.
 */
class GuardedProviderTest extends TestCase
{
    private FakeGateway $fake;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    /**
     * The guard around a fake supporting exactly $capabilities. Resolved through
     * Cashier::provider() so the guard's own ensureSupports() reads the very driver it wraps.
     *
     * @param  array<int, Capability>  $capabilities
     */
    private function guard(array $capabilities): GatewayProvider
    {
        $fake = $this->fake = new FakeGateway($capabilities);
        Cashier::extend('fake', fn () => $fake);

        return Cashier::provider('fake');
    }

    public function test_provider_hands_back_the_guard(): void
    {
        $this->assertInstanceOf(GuardedProvider::class, $this->guard([Capability::Charges]));
    }

    public function test_it_forwards_capabilities_and_supports_to_the_wrapped_driver(): void
    {
        $guard = $this->guard([Capability::Charges]);

        $this->assertSame([Capability::Charges], $guard->capabilities());
        $this->assertTrue($guard->supports(Capability::Charges));
        $this->assertFalse($guard->supports(Capability::Refunds));
    }

    public function test_it_refuses_an_operation_the_driver_does_not_support(): void
    {
        $guard = $this->guard([]);

        $this->expectException(UnsupportedOperationException::class);
        $guard->charge(new User, 1000, 'pm_x');
    }

    public function test_it_delegates_an_operation_the_driver_supports(): void
    {
        $guard = $this->guard([Capability::Charges]);

        $guard->charge(new User, 1000, 'pm_x');

        $this->fake->assertCharged();
    }

    public function test_it_refuses_refund_when_the_driver_lacks_the_capability(): void
    {
        // A second op, gated on its own capability — Charges support does not buy Refunds.
        $guard = $this->guard([Capability::Charges]);

        $this->expectException(UnsupportedOperationException::class);
        $guard->refund(new User, 'pay_1');
    }

    public function test_it_delegates_refund_when_supported(): void
    {
        $guard = $this->guard([Capability::Refunds]);

        $guard->refund(new User, 'pay_1');

        $this->fake->assertRefunded();
    }

    public function test_it_passes_a_webhook_through_without_a_capability_gate(): void
    {
        // A gateway that delivers a webhook self-evidently supports webhooks — even a fake that
        // declares nothing must be able to hand one to the controller.
        $guard = $this->guard([]);

        $guard->webhook('raw-body', ['signature' => 'sig']);

        $this->assertSame('raw-body', $this->fake->lastWebhookContent);
    }

    public function test_it_wraps_a_new_subscription_in_the_guarded_builder(): void
    {
        $guard = $this->guard([Capability::Subscriptions]);

        $builder = $guard->newSubscription(new User, 'default', 'price_1');

        $this->assertInstanceOf(GuardedSubscriptionBuilder::class, $builder);
    }

    public function test_it_gates_checkout_on_the_request_shape(): void
    {
        // The guard reads the request's shape (prices vs amount) — a price catalogue on an
        // amount-only gateway is refused before any driver sees it.
        $guard = $this->guard([Capability::CheckoutAmount]);

        $this->expectException(UnsupportedOperationException::class);
        $guard->checkout(new User, CheckoutRequest::forPrices(['price_1' => 1]));
    }

    /**
     * @return array<string, array{string|array<int, string>}>
     */
    public static function emptyPrices(): array
    {
        return [
            'an empty array' => [[]],
            'an empty string' => [''],
            'an array holding an empty string' => [['']],
        ];
    }

    /**
     * @param  string|array<int, string>  $prices
     */
    #[DataProvider('emptyPrices')]
    public function test_subscribing_to_no_price_is_refused(string|array $prices): void
    {
        // Contracts\SubscriptionOperations:29 has declared this since it was written and
        // nothing in this package threw it — the fourth instance of the defect
        // .claude/rules/exceptions.md exists for, after charge(), quantity() and trialDays().
        // It held only by accident of which driver was installed: Revolut happens to check,
        // FakeGateway does not, so an app's own test suite proved nothing.
        //
        // The reference's wording, deliberately — laravel/cashier's Subscription::swap() says
        // "Please provide at least one price when swapping", and DTO\CheckoutRequest already
        // borrowed it for checkout.
        $guard = $this->guard([Capability::Subscriptions]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one price');

        $guard->newSubscription(new User, 'default', $prices);
    }

    /**
     * @param  string|array<int, string>  $prices
     */
    #[DataProvider('emptyPrices')]
    public function test_swapping_to_no_price_is_refused(string|array $prices): void
    {
        // Contracts\SubscriptionOperations:95, the same declaration on the same contract. A swap
        // to nothing is the case the reference names by name.
        $guard = $this->guard([Capability::Subscriptions, Capability::SubscriptionSwapImmediate]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one price');

        $guard->swapSubscription(new User, 'default', $prices);
    }

    public function test_the_capability_gate_outranks_the_empty_price_guard(): void
    {
        // Same ordering as quantity and trialDays: a gateway that cannot subscribe at all must
        // say THAT, not quibble with the argument — otherwise an app fixing the "no price" it
        // was told about reaches the real answer only on the second try.
        $guard = $this->guard([]);

        $this->expectException(UnsupportedOperationException::class);

        $guard->newSubscription(new User, 'default', []);
    }

    public function test_a_real_price_still_reaches_the_driver(): void
    {
        // The guard must not tax the ordinary path.
        $guard = $this->guard([Capability::Subscriptions]);

        $this->assertInstanceOf(
            GuardedSubscriptionBuilder::class,
            $guard->newSubscription(new User, 'default', ['price_1']),
        );
    }
}
