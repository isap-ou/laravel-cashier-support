<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Money\Currency;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Data;

/**
 * A single payment / charge.
 */
class Payment extends Data
{
    /**
     * @param  int  $amount  Amount in minor units (cents).
     * @param  string|null  $clientSecret  The opaque token a client SDK needs to complete or
     *                                     resume this payment (e.g. 3DS/SCA). Provider-neutral:
     *                                     Stripe client_secret, Adyen sessionData, Braintree
     *                                     client token, Revolut order token — null when the
     *                                     gateway has no such concept. Mirrors CheckoutSession.
     */
    public function __construct(
        public string $id,
        public int $amount,
        #[WithCastAndTransformer(CurrencyCast::class)]
        public Currency $currency,
        public PaymentStatus $status,
        public ?string $paymentMethodId = null,
        public ?CarbonImmutable $createdAt = null,
        public ?string $clientSecret = null,
    ) {}

    /**
     * Whether the payment is waiting for a payment method before it can proceed.
     *
     * Encodes Laravel\Cashier\Payment::requiresPaymentMethod()
     * (vendor/laravel/cashier/src/Payment.php:80).
     */
    public function requiresPaymentMethod(): bool
    {
        return $this->status === PaymentStatus::RequiresPaymentMethod;
    }

    /**
     * Whether the payment needs an additional customer action (e.g. 3DS/SCA authentication)
     * before it can complete.
     *
     * Encodes Laravel\Cashier\Payment::requiresAction()
     * (vendor/laravel/cashier/src/Payment.php:90).
     */
    public function requiresAction(): bool
    {
        return $this->status === PaymentStatus::RequiresAction;
    }

    /**
     * Whether the payment must be confirmed before it can complete.
     *
     * Encodes Laravel\Cashier\Payment::requiresConfirmation()
     * (vendor/laravel/cashier/src/Payment.php:100).
     */
    public function requiresConfirmation(): bool
    {
        return $this->status === PaymentStatus::RequiresConfirmation;
    }
}
