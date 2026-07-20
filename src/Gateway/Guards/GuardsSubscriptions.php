<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\Builders\GuardedSubscriptionBuilder;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\Proration;
use Isapp\CashierSupport\Enums\SwapTiming;

/**
 * Capability gating for the SubscriptionOperations surface, composed into GuardedProvider.
 *
 * Swap gates on the caller's intent — a defer-only gateway must reject an Immediate swap — so
 * its capability comes from the timing, not the method. Pause is immediate-only (#72), so it
 * gates its one capability directly, like resume and cancel.
 *
 * @internal Composed into Gateway\GuardedProvider, which is what Cashier::provider() returns. An app reaches this through the facade, never by name. Not public surface: outside the backward-compatibility promise in README.
 */
trait GuardsSubscriptions
{
    /**
     * {@inheritDoc}
     */
    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder
    {
        $this->ensure(Capability::Subscriptions);
        $this->ensurePricesArePresent($prices, 'subscribing');
        $this->ensureTaxRatesSupported($billable);

        // Wrap the driver's builder so its capability-bearing setters (trial, quantity,
        // metadata) are gated too — the one surface this guard cannot see, gated at its own
        // boundary. Both guards are thus born here.
        return new GuardedSubscriptionBuilder(
            $this->inner()->newSubscription($billable, $type, $prices),
            $this->guardDriver(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription
    {
        $this->ensure(Capability::Subscriptions);

        return $this->inner()->cancelSubscription($billable, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription
    {
        $this->ensure(Capability::SubscriptionCancelNow);

        return $this->inner()->cancelSubscriptionNow($billable, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription
    {
        $this->ensure(Capability::SubscriptionResume);

        return $this->inner()->resumeSubscription($billable, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function pauseSubscription(
        Model $billable,
        string $type = 'default',
        ?DateTimeInterface $until = null,
    ): Subscription {
        $this->ensure(Capability::SubscriptionPauseImmediate);

        return $this->inner()->pauseSubscription($billable, $type, $until);
    }

    /**
     * {@inheritDoc}
     */
    public function swapSubscription(
        Model $billable,
        string $type,
        string|array $prices,
        SwapTiming $timing = SwapTiming::Immediate,
        Proration $proration = Proration::Prorate,
        array $options = [],
    ): Subscription {
        $this->ensure($timing->capability());
        $this->ensurePricesArePresent($prices, 'swapping');
        // Tax rates are consumed on a swap as well as on create — guarding only the first
        // would let a swap discard them in silence.
        $this->ensureTaxRatesSupported($billable);
        // Prorate is the ungated baseline; only NoProrate names a capability a gateway may lack.
        if ($capability = $proration->capability()) {
            $this->ensure($capability);
        }

        return $this->inner()->swapSubscription($billable, $type, $prices, $timing, $proration, $options);
    }

    /**
     * Refuse a subscription or a swap that names no price to bill.
     *
     * **Contracts\SubscriptionOperations has declared this on both methods since it was
     * written** — ":29" and ":95", "@throws InvalidArgumentException When the prices are
     * empty" — and nothing in this package threw it. Fourth instance of the defect
     * `.claude/rules/exceptions.md` exists for, after charge(), quantity() and trialDays(),
     * and the worst-behaved of the four: the promise held only by accident of which driver
     * was installed. A driver that happens to check made it true; Testing\FakeGateway does
     * not, so an app's own test suite proved nothing either way.
     *
     * A price is what a subscription bills on, so naming none is a programmer error and not
     * a decline — SPL's InvalidArgumentException, per the exceptions rule. The wording is the
     * reference's: laravel/cashier's Subscription::swap() says "Please provide at least one
     * price when swapping", and DTO\CheckoutRequest:64 already borrowed it for checkout.
     *
     * The SECOND half of the contract's sentence — "or more of them are given than the
     * provider bills a subscription on" — deliberately stays the driver's. Only a driver
     * knows how many prices its gateway bills one subscription on; support does not, and
     * guessing a number here would be the abstraction describing one gateway.
     *
     * @param  string|array<int, string>  $prices
     *
     * @throws InvalidArgumentException When $prices names no usable price.
     */
    private function ensurePricesArePresent(string|array $prices, string $action): void
    {
        $named = array_filter(
            is_array($prices) ? $prices : [$prices],
            static fn (string $price): bool => trim($price) !== '',
        );

        if ($named === []) {
            throw new InvalidArgumentException("Please provide at least one price when {$action}.");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateSubscriptionQuantity(
        Model $billable,
        string $type,
        int $quantity,
        string $price,
        Proration $proration = Proration::Prorate,
    ): Subscription {
        $this->ensure(Capability::SubscriptionQuantityUpdate);
        // Prorate is the ungated baseline; only NoProrate names a capability a gateway may lack.
        if ($capability = $proration->capability()) {
            $this->ensure($capability);
        }

        return $this->inner()->updateSubscriptionQuantity($billable, $type, $quantity, $price, $proration);
    }
}
