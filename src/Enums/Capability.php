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
    // Having customers and being able to change one are different facts about a gateway, and
    // the references prove it rather than suggest it: Stripe pushes name/email out
    // (ManagesCustomer.php:266), Paddle has no customer update at all and only accepts them
    // back in via a webhook. A gateway that can create a customer it can never correct is a
    // real gateway; this is how it says so.
    case CustomersUpdate = 'customers.update';
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
    case SubscriptionMetadata = 'subscription.metadata';
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

    /**
     * The GatewayProvider methods that implement this capability, if any can.
     *
     * This map is what lets `Gateway\BaseGateway::supports()` read a driver's capabilities
     * off its code instead of a hand-kept list, so "declares it" and "wrote it" cannot
     * drift apart. A capability holds only when EVERY method here is overridden: a gateway
     * that can list invoices but not render one does not support Invoices.
     *
     * **Eight cases return `[]`, and that is the design, not a gap.** An interface — or a
     * method — can say *the operation exists*; it cannot say *which intent it honours*:
     *
     *  - `swapSubscription()` is ONE method behind SwapImmediate and SwapAtPeriodEnd. Revolut
     *    schedules a swap for cycle end and cannot do it now: same method, one capability.
     *  - `checkout()` is ONE method behind CheckoutPrices and CheckoutAmount — see
     *    DTO\CheckoutRequest::capability().
     *  - Trials, Quantity, Metadata and Taxes are setters on Contracts\SubscriptionBuilder,
     *    which is not the gateway at all; Builders\GuardedSubscriptionBuilder gates those.
     *
     * Those eight are declared by the driver (`BaseGateway::declaredCapabilities()`). The
     * split is not cosmetic: it is why interfaces alone could never have replaced this enum.
     *
     * @return array<int, string>
     */
    public function methods(): array
    {
        return match ($this) {
            self::Charges => ['charge'],
            self::Refunds => ['refund'],
            // Deliberately NOT ['createCustomer', 'asCustomer', 'updateCustomer']: a capability
            // holds only when every method here is overridden, so folding update in would strip
            // Customers from every driver that has not written one yet — silently, since a lie
            // about capabilities reads exactly like the truth until an app calls the method.
            self::Customers => ['createCustomer', 'asCustomer'],
            self::CustomersUpdate => ['updateCustomer'],
            self::Subscriptions => ['newSubscription', 'cancelSubscription'],
            self::SubscriptionCancelNow => ['cancelSubscriptionNow'],
            self::SubscriptionPause => ['pauseSubscription'],
            self::SubscriptionResume => ['resumeSubscription'],
            self::PaymentMethodsList => ['paymentMethods', 'defaultPaymentMethod'],
            self::PaymentMethodsAdd => ['addPaymentMethod'],
            self::PaymentMethodsDelete => ['deletePaymentMethod'],
            self::Invoices => ['invoices', 'findInvoice', 'downloadInvoice'],
            self::Webhooks => ['webhook'],

            self::SubscriptionSwapImmediate,
            self::SubscriptionSwapAtPeriodEnd,
            self::CheckoutPrices,
            self::CheckoutAmount,
            self::SubscriptionTrials,
            self::SubscriptionQuantity,
            self::SubscriptionMetadata,
            self::Taxes => [],
        };
    }
}
