<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\Subscription;

/**
 * Dispatched when a price change has been SCHEDULED and has not taken effect.
 *
 * Deliberately not SubscriptionUpdated: nothing about what the customer is
 * billed on has changed yet, and a listener that provisions entitlements on
 * "updated" would grant the new plan a cycle early. The change lands later —
 * that moment is what SubscriptionUpdated announces.
 *
 * The subscription it carries names the pending price and, where the gateway
 * says so, the date it starts.
 */
class SubscriptionPriceChangeScheduled
{
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly Subscription $subscription,
    ) {}
}
