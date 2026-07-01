<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a payment requires an additional action (e.g. 3DS confirmation)
 * before it can be completed.
 */
class IncompletePaymentException extends CashierException
{
    public function __construct(
        string $message = 'The payment requires additional confirmation.',
        public readonly ?string $paymentId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Create the exception for a specific payment identifier.
     */
    public static function forPayment(string $paymentId, string $message = 'The payment requires additional confirmation.'): self
    {
        return new self($message, $paymentId);
    }
}
