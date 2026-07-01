<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * Granular feature flags a gateway provider may declare support for.
 *
 * Concerns check Cashier::ensureSupports() before delegating; unsupported
 * operations throw UnsupportedOperationException.
 */
enum Capability: string
{
    case Charges = 'charges';
    case Refunds = 'refunds';
    case Customers = 'customers';
    case Subscriptions = 'subscriptions';
    case SubscriptionPause = 'subscription.pause';
    case SubscriptionResume = 'subscription.resume';
    case SubscriptionSwap = 'subscription.swap';
    case SubscriptionTrials = 'subscription.trials';
    case PaymentMethodsAdd = 'payment_methods.add';
    case PaymentMethodsList = 'payment_methods.list';
    case PaymentMethodsDelete = 'payment_methods.delete';
    case Checkout = 'checkout';
    case Invoices = 'invoices';
    case Taxes = 'taxes';
    case Webhooks = 'webhooks';
}
