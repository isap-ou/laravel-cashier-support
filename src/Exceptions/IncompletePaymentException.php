<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

use Isapp\CashierSupport\Enums\PaymentStatus;

/**
 * Thrown when a payment requires an additional action (e.g. 3DS/SCA confirmation)
 * before it can be completed.
 *
 * It carries what a caller needs to RESUME the payment — the identifier, the client
 * secret the frontend hands back to the gateway, and the incomplete status — not just
 * the fact that it stalled. The Payment DTO itself is deliberately not held here: the
 * Exceptions layer depends only on Enums (deptrac), so it carries scalars and the enum.
 */
class IncompletePaymentException extends CashierException
{
    public function __construct(
        string $message = 'The payment requires additional confirmation.',
        public readonly ?string $paymentId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?PaymentStatus $status = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The payment is waiting for a payment method before it can proceed.
     *
     * Encodes Laravel\Cashier\Exceptions\IncompletePayment::paymentMethodRequired().
     */
    public static function requiresPaymentMethod(?string $paymentId = null, ?string $clientSecret = null): self
    {
        return new self(
            'The payment attempt failed because of an invalid payment method.',
            $paymentId,
            $clientSecret,
            PaymentStatus::RequiresPaymentMethod,
        );
    }

    /**
     * The payment needs an additional customer action (e.g. 3DS/SCA authentication)
     * before it can complete.
     *
     * Encodes Laravel\Cashier\Exceptions\IncompletePayment::requiresAction().
     */
    public static function requiresAction(?string $paymentId = null, ?string $clientSecret = null): self
    {
        return new self(
            'The payment requires additional action before it can be completed.',
            $paymentId,
            $clientSecret,
            PaymentStatus::RequiresAction,
        );
    }

    /**
     * The payment must be confirmed before it can complete.
     *
     * Encodes Laravel\Cashier\Exceptions\IncompletePayment::requiresConfirmation().
     */
    public static function requiresConfirmation(?string $paymentId = null, ?string $clientSecret = null): self
    {
        return new self(
            'The payment requires confirmation before it can be completed.',
            $paymentId,
            $clientSecret,
            PaymentStatus::RequiresConfirmation,
        );
    }
}
