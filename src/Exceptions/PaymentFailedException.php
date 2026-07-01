<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a charge or payment is declined or otherwise fails.
 */
class PaymentFailedException extends CashierException
{
    public function __construct(
        string $message = 'The payment failed.',
        public readonly ?string $paymentId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Create the exception for a specific payment identifier.
     */
    public static function forPayment(string $paymentId, string $message = 'The payment failed.'): self
    {
        return new self($message, $paymentId);
    }
}
