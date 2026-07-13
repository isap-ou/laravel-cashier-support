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
    case SubscriptionCancelNow = 'subscription.cancel_now';
    case SubscriptionPause = 'subscription.pause';
    case SubscriptionResume = 'subscription.resume';
    // Timing is not a detail of a swap; it IS the swap. A gateway that only
    // changes the plan at cycle end cannot honour "upgrade me now", and an app
    // must be able to say which it needs — see Enums\SwapTiming.
    case SubscriptionSwapImmediate = 'subscription.swap.immediate';
    case SubscriptionSwapAtPeriodEnd = 'subscription.swap.at_period_end';
    case SubscriptionTrials = 'subscription.trials';
    case SubscriptionQuantity = 'subscription.quantity';
    case PaymentMethodsAdd = 'payment_methods.add';
    case PaymentMethodsList = 'payment_methods.list';
    case PaymentMethodsDelete = 'payment_methods.delete';
    // One gateway takes catalogue price ids, another takes an amount. Both are
    // legitimate; a single Checkout flag claimed only the first existed.
    case CheckoutPrices = 'checkout.prices';
    case CheckoutAmount = 'checkout.amount';
    case Invoices = 'invoices';
    case Taxes = 'taxes';
    case Webhooks = 'webhooks';
}
