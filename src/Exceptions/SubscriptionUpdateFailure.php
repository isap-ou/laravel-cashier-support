<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a subscription update (swap, quantity change, resume) fails.
 */
class SubscriptionUpdateFailure extends CashierException
{
    /**
     * Create the exception when swapping to an unknown price.
     */
    public static function invalidPrice(string $price): self
    {
        return new self("Cannot swap the subscription to unknown price [{$price}].");
    }
}
