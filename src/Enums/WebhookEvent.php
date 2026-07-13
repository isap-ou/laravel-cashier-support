<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * Provider-agnostic webhook event names.
 *
 * Concrete providers map their native event names onto these cases.
 */
enum WebhookEvent: string
{
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionUpdated = 'subscription.updated';
    case SubscriptionCanceled = 'subscription.canceled';
    case SubscriptionPastDue = 'subscription.past_due';
    /**
     * Only for gateways that can classify a renewal at parse time. Others learn
     * it after refetching, and signal it with the SubscriptionRenewed event.
     */
    case SubscriptionRenewed = 'subscription.renewed';
    case RefundCompleted = 'refund.completed';
}
