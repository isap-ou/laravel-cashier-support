<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\Subscription;

/**
 * Dispatched when a subscription's billing cycle has been paid for — a renewal.
 *
 * The signal an app needs to extend entitlement and send a receipt. It carries
 * the invoice that settled the cycle, so a listener does not have to go and find
 * it.
 *
 * It is a typed event rather than a WebhookEvent case on purpose: a gateway may
 * not be able to classify a renewal at parse time. Revolut, for one, only says
 * "an order completed" — that the order paid for a billing cycle is a fact its
 * driver learns after refetching the order, long after the webhook was parsed.
 */
class SubscriptionRenewed
{
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly Subscription $subscription,
        public readonly Invoice $invoice,
    ) {}
}
