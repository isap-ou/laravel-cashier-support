<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\SubscriptionUpdateFailure;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Subscription lifecycle operations at the gateway provider.
 */
interface SubscriptionOperations
{
    /**
     * Begin creating a new subscription of the given type.
     *
     * @param  string  $type  The internal subscription type (e.g. "default").
     * @param  string|array<int, string>  $prices  One or more provider price identifiers.
     *
     * @throws UnsupportedOperationException When the provider does not support subscriptions.
     * @throws InvalidArgumentException When the prices are empty, or more of them are
     *                                  given than the provider bills a subscription on.
     */
    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder;

    /**
     * Cancel a subscription at the end of its current billing period.
     *
     * @throws SubscriptionUpdateFailure When there is no such subscription to cancel.
     * @throws CashierException When the gateway call fails.
     */
    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription;

    /**
     * Cancel a subscription immediately.
     *
     * @throws UnsupportedOperationException When the provider cannot cancel immediately.
     * @throws SubscriptionUpdateFailure When there is no such subscription to cancel.
     * @throws CashierException When the gateway call fails.
     */
    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription;

    /**
     * Resume a subscription that is within its grace period.
     *
     * @throws UnsupportedOperationException When the provider cannot resume subscriptions.
     * @throws SubscriptionUpdateFailure When there is no such subscription to resume.
     * @throws CashierException When the gateway call fails.
     */
    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription;

    /**
     * Pause an active subscription.
     *
     * @throws UnsupportedOperationException When the provider cannot pause subscriptions.
     * @throws SubscriptionUpdateFailure When there is no such subscription to pause.
     * @throws CashierException When the gateway call fails.
     */
    public function pauseSubscription(Model $billable, string $type = 'default'): Subscription;

    /**
     * Swap a subscription to one or more new prices.
     *
     * $timing is the caller's intent, not a hint: a gateway that can only change
     * the plan at cycle end cannot honour Immediate, and says so rather than
     * silently deferring an upgrade the caller believed was applied.
     *
     * @param  string|array<int, string>  $prices
     * @param  array<string, mixed>  $options
     *
     * @throws SubscriptionUpdateFailure When there is no such subscription, or the swap fails.
     * @throws UnsupportedOperationException When the provider cannot swap with this timing.
     * @throws InvalidArgumentException When the prices are empty, or more of them are
     *                                  given than the provider bills a subscription on.
     * @throws CashierException When the gateway call fails.
     */
    public function swapSubscription(
        Model $billable,
        string $type,
        string|array $prices,
        SwapTiming $timing = SwapTiming::Immediate,
        array $options = [],
    ): Subscription;
}
