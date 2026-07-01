<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when the package or a provider is misconfigured.
 */
class InvalidConfigurationException extends CashierException
{
    /**
     * Create the exception for a missing configuration key.
     */
    public static function missingKey(string $key): self
    {
        return new self("Missing required configuration value [{$key}].");
    }

    /**
     * Create the exception when no gateway provider is bound.
     */
    public static function noProviderBound(): self
    {
        return new self('No gateway provider is bound. Install and register a concrete cashier provider.');
    }
}
