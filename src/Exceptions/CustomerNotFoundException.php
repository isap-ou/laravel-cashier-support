<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a billable entity has no corresponding customer at the provider.
 */
class CustomerNotFoundException extends CashierException
{
    /**
     * Create the exception for a specific customer identifier.
     */
    public static function withId(string $customerId): self
    {
        return new self("No customer found for identifier [{$customerId}].");
    }

    /**
     * Create the exception when the billable entity is not yet a customer.
     */
    public static function notCreated(): self
    {
        return new self('The billable entity is not a customer yet. Create it first.');
    }
}
