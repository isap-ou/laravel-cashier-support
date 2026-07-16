<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\Subscription;

/**
 * Dispatched when a subscription's payment has failed and it is awaiting
 * resolution.
 *
 * A distinct signal from SubscriptionUpdated: dunning, a grace-period warning
 * and a suspension are all driven by this, and none of them should have to
 * infer it from a generic "something changed".
 */
class SubscriptionPastDue
{
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly Subscription $subscription,
    ) {}
}
