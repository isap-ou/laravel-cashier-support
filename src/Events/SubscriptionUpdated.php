<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Subscription;

/**
 * Dispatched when a subscription has been updated (swap, quantity, resume, pause).
 */
class SubscriptionUpdated
{
    public function __construct(
        public readonly Model $billable,
        public readonly Subscription $subscription,
    ) {}
}
