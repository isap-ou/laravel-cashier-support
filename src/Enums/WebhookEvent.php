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
    case RefundCompleted = 'refund.completed';
}
