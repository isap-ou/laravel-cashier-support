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
    // Pause is immediate-only across every shipped driver: Stripe's pause_collection pauses now
    // (and does not even change the status). Pause-at-period-end was Paddle-reference-only — no
    // driver implements it or ever will — so #72 removed it; the `.immediate` value stays as the
    // wire name. Pause is now a single intent, read off pauseSubscription() like resume.
    case SubscriptionPauseImmediate = 'subscription.pause.immediate';
    case SubscriptionResume = 'subscription.resume';
    // Reading a subscription's latest payment is its own fact about a gateway, and the
    // references prove it rather than suggest it: Stripe exposes it (latestPayment(),
    // Subscription.php:1412), Paddle exposes it (lastPayment()). Its point is a subscription
    // created incomplete/pending — the Payment carries the clientSecret that completes the
    // first charge. A gateway that never leaves a subscription unpaid, or cannot read a payment
    // back, must be able to refuse rather than return a silent null that reads as "nothing due".
    case SubscriptionLatestPayment = 'subscription.latest_payment';
    // Timing is not a detail of a swap; it IS the swap. A gateway that only
    // changes the plan at cycle end cannot honour "upgrade me now", and an app
    // must be able to say which it needs — see Enums\SwapTiming.
    case SubscriptionSwapImmediate = 'subscription.swap.immediate';
    case SubscriptionSwapAtPeriodEnd = 'subscription.swap.at_period_end';
    case SubscriptionTrials = 'subscription.trials';
    case SubscriptionQuantity = 'subscription.quantity';
    // Billing per seat and being able to change the seat count later are different facts,
    // and the references agree they are: both carry a quantity into creation AND expose
    // updateQuantity() afterwards, so a gateway may honestly have the first without the
    // second. Kept apart from SubscriptionQuantity for the reason spelled out on Customers
    // below — folding them would silently take the builder setter away from every driver
    // that has not written the mutation yet.
    case SubscriptionQuantityUpdate = 'subscription.quantity.update';
    case SubscriptionMetadata = 'subscription.metadata';
    // Whether a gateway lets the caller suppress proration on a mid-cycle change. Both references
    // can prorate AND not prorate, so the axis itself is agreement, not divergence — but a third
    // gateway may only ever prorate, and it must be able to refuse "do not prorate" rather than
    // silently prorate anyway. Only the non-default intent is gated: a plain prorated swap needs
    // no capability, see Enums\Proration.
    case SubscriptionNoProration = 'subscription.no_proration';
    case PaymentMethodsAdd = 'payment_methods.add';
    case PaymentMethodsList = 'payment_methods.list';
    case PaymentMethodsDelete = 'payment_methods.delete';
    // One gateway takes catalogue price ids, another takes an amount. Both are
    // legitimate; a single Checkout flag claimed only the first existed.
    case CheckoutPrices = 'checkout.prices';
    case CheckoutAmount = 'checkout.amount';
    case Invoices = 'invoices';
    case Taxes = 'taxes';
    // Whether a gateway's invoices can carry a discount at all. Unlike the others this backs no
    // operation and no setter — it is a fact about the shape of DTO\Invoice this gateway can fill
    // (the `discount` field), so an app can ask before it expects one. Stripe and Paddle both
    // discount; a gateway that cannot must be able to say so rather than return a silent zero.
    case Discounts = 'discounts';
    case Webhooks = 'webhooks';

    /**
     * The GatewayProvider methods that implement this capability, if any can.
     *
     * This map is what lets `Gateway\BaseGateway::supports()` read a driver's capabilities
     * off its code instead of a hand-kept list, so "declares it" and "wrote it" cannot
     * drift apart. A capability holds only when EVERY method here is overridden: a gateway
     * that can list invoices but not render one does not support Invoices.
     *
     * **Ten cases return `[]`, and that is the design, not a gap.** An interface — or a
     * method — can say *the operation exists*; it cannot say *which intent it honours*:
     *
     *  - `swapSubscription()` is ONE method behind SwapImmediate and SwapAtPeriodEnd. Revolut
     *    schedules a swap for cycle end and cannot do it now: same method, one capability.
     *  - `checkout()` is ONE method behind CheckoutPrices and CheckoutAmount — see
     *    DTO\CheckoutRequest::capability().
     *  - Trials, Quantity, Metadata and Taxes are setters on Contracts\SubscriptionBuilder,
     *    which is not the gateway at all; Builders\GuardedSubscriptionBuilder gates those.
     *  - Discounts backs no operation and no setter — it is a fact about the shape a gateway's
     *    DTO\Invoice can carry (the `discount` field), so there is no method to read it off.
     *  - `SubscriptionNoProration` is a sub-mode of `swapSubscription()` AND
     *    `updateSubscriptionQuantity()` — a per-call intent (Enums\Proration), not a method — and a
     *    gateway that always prorates overrides both yet honours neither.
     *
     * Those ten are declared by the driver (`BaseGateway::declaredCapabilities()`). The
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
            self::SubscriptionResume => ['resumeSubscription'],
            // One method, one intent, read off the code like resume: a driver that overrides it
            // supports the capability, one that inherits the refusal does not.
            self::SubscriptionLatestPayment => ['subscriptionLatestPayment'],
            // One method, one intent, read off the code exactly like resume above — since #72
            // removed pause-at-period-end there is no second timing to make it unreadable.
            self::SubscriptionPauseImmediate => ['pauseSubscription'],
            // The mutation is the gateway's; the setter below is the builder's. Only this one
            // can be read off a method — which is exactly why the two cannot share a case.
            self::SubscriptionQuantityUpdate => ['updateSubscriptionQuantity'],
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
            self::SubscriptionNoProration,
            self::Taxes,
            self::Discounts => [],
        };
    }
}
