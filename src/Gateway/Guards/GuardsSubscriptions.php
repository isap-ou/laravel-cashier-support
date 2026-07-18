<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Builders\GuardedSubscriptionBuilder;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SwapTiming;

/**
 * Capability gating for the SubscriptionOperations surface, composed into GuardedProvider.
 *
 * Swap gates on the caller's intent — a defer-only gateway must reject an Immediate swap — so
 * its capability comes from the timing, not the method. Pause is immediate-only (#72), so it
 * gates its one capability directly, like resume and cancel.
 */
trait GuardsSubscriptions
{
    /**
     * {@inheritDoc}
     */
    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder
    {
        $this->ensure(Capability::Subscriptions);
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
        array $options = [],
    ): Subscription {
        $this->ensure($timing->capability());
        // Tax rates are consumed on a swap as well as on create — guarding only the first
        // would let a swap discard them in silence.
        $this->ensureTaxRatesSupported($billable);

        return $this->inner()->swapSubscription($billable, $type, $prices, $timing, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function updateSubscriptionQuantity(
        Model $billable,
        string $type,
        int $quantity,
        string $price,
    ): Subscription {
        $this->ensure(Capability::SubscriptionQuantityUpdate);

        return $this->inner()->updateSubscriptionQuantity($billable, $type, $quantity, $price);
    }
}
