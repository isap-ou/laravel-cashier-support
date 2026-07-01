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
    case Canceled = 'canceled';
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Paused = 'paused';

    /**
     * Whether the subscription is in a valid, usable state.
     */
    public function isActive(): bool
    {
        return $this === self::Active || $this === self::Trialing;
    }
}
