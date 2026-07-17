<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

use IsapOu\EnumHelpers\Concerns\InteractWithCollection;
use IsapOu\EnumHelpers\Contracts\HasLabel;
use Isapp\CashierSupport\Enums\Concerns\HasCashierLabel;

/**
 * Lifecycle status of a subscription.
 *
 * Mirrors the statuses used by laravel/cashier-stripe.
 */
enum SubscriptionStatus: string implements HasLabel
{
    use HasCashierLabel;
    use InteractWithCollection;

    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';

    /**
     * Whether a subscription is paused is answered by Models\Subscription::paused(), which reads
     * the paused_at column — NOT by this status. The references put the paused fact in different
     * places: Paddle moves the status here (Subscription.php:314), while Stripe's pause_collection
     * pauses payment collection and leaves the status untouched — and Stripe reaches status=paused
     * only when a trial ends with no payment method (/api/subscriptions/object), a different
     * concept an app never requests. So a driver may report a paused subscription with this status
     * or without it; the column is the fact both gateways can carry, and the predicate reads it.
     * (Distinguishing Stripe's two meanings of `paused` is deferred — see #30's spec, Non-goals.)
     */
    case Paused = 'paused';

    /**
     * Whether the subscription is in a valid, usable state.
     *
     * Not the whole access question: a status can withhold access without this
     * being the reason, so a caller deciding access wants Models\Subscription::active()
     * — which weighs this against the dates — or at least deniesAccess() alongside it.
     */
    public function isActive(): bool
    {
        return $this === self::Active || $this === self::Trialing;
    }

    /**
     * Whether the status withholds access on its own, whatever the dates say.
     *
     * These two mean the money never arrived — dunning ran out, or an initial
     * payment was never completed. A paid-through date cannot outrank that, so
     * unlike past_due and incomplete there is no policy to apply and nothing to
     * configure: Stripe excludes exactly these two unconditionally, while
     * gating the other two behind $deactivatePastDue / $deactivateIncomplete
     * (vendor/laravel/cashier/src/Subscription.php:232-235).
     */
    public function deniesAccess(): bool
    {
        return $this === self::Unpaid || $this === self::IncompleteExpired;
    }
}
