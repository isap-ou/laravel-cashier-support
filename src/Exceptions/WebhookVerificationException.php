<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when an incoming webhook fails signature verification.
 */
class WebhookVerificationException extends CashierException
{
    public static function invalidSignature(): self
    {
        return new self('The webhook signature could not be verified.');
    }
}
