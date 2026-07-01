<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Subscription;

/**
 * Dispatched when a subscription has been created.
 */
class SubscriptionCreated
{
    public function __construct(
        public readonly Model $billable,
        public readonly Subscription $subscription,
    ) {}
}
