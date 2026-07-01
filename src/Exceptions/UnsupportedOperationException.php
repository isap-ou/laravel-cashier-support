<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

use Isapp\CashierSupport\Enums\Capability;

/**
 * Thrown when an operation is requested that the active provider does not support.
 *
 * Concerns call Cashier::ensureSupports() before delegating; providers must not
 * build local workarounds for missing capabilities.
 */
class UnsupportedOperationException extends CashierException
{
    public function __construct(
        string $message,
        public readonly ?Capability $capability = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Create the exception for an unsupported capability.
     */
    public static function forCapability(Capability $capability): self
    {
        return new self(
            "The active gateway provider does not support the [{$capability->value}] capability.",
            $capability,
        );
    }
}
