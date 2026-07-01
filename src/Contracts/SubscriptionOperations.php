<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Subscription;
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
     */
    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder;

    /**
     * Cancel a subscription at the end of its current billing period.
     */
    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription;

    /**
     * Cancel a subscription immediately.
     */
    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription;

    /**
     * Resume a subscription that is within its grace period.
     *
     * @throws UnsupportedOperationException When the provider cannot resume subscriptions.
     */
    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription;

    /**
     * Pause an active subscription.
     *
     * @throws UnsupportedOperationException When the provider cannot pause subscriptions.
     */
    public function pauseSubscription(Model $billable, string $type = 'default'): Subscription;

    /**
     * Swap a subscription to one or more new prices.
     *
     * @param  string|array<int, string>  $prices
     * @param  array<string, mixed>  $options
     *
     * @throws SubscriptionUpdateFailure When the swap fails.
     * @throws UnsupportedOperationException When the provider cannot swap subscriptions.
     */
    public function swapSubscription(Model $billable, string $type, string|array $prices, array $options = []): Subscription;
}
