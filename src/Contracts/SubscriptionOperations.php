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

    /**
     * Set how many of a subscription's price the entity is billed for.
     *
     * $quantity is absolute, never a delta. In both references, increment and decrement always
     * reduce to an updateQuantity and never to an endpoint of their own (Stripe
     * Subscription.php:445,491; Paddle :488,518) — so relative arithmetic is the caller's side
     * of the boundary, and a gateway is only ever told the number to land on. (Stripe's
     * multi-price path reduces to the *item's* updateQuantity, SubscriptionItem.php:112, rather
     * than the subscription's; the shape that matters here survives that — no gateway anywhere
     * has an "add one seat" operation to model.)
     *
     * **$price is required here, where both references make it optional** — and the
     * difference is the boundary, not a preference. Their `updateQuantity($quantity, $price =
     * null)` lives ON the subscription (Stripe Subscription.php:518, Paddle :532), which
     * holds its own items and so can resolve "the only one" itself. This method lives on the
     * gateway, which holds no local records at all, so null would mean asking a driver to
     * guess which line to bill. Concerns\ManagesSubscriptions keeps the optional form for the
     * app and resolves it against the local items before calling here; ambiguity is refused
     * on that side, and by the time a driver is asked, the answer is already named.
     *
     * @param  string  $type  The subscription type.
     * @param  int  $quantity  The new quantity, always >= 1.
     * @param  string  $price  Which item to change, always named.
     *
     * @throws UnsupportedOperationException When the provider cannot change a quantity.
     * @throws SubscriptionUpdateFailure When there is no such subscription, or no such price on it.
     * @throws CashierException When the gateway call fails.
     */
    public function updateSubscriptionQuantity(
        Model $billable,
        string $type,
        int $quantity,
        string $price,
    ): Subscription;
}
