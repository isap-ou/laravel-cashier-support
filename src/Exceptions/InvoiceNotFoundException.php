<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when an invoice does not exist, or is not the billable entity's.
 *
 * A CashierException so `catch (CashierException)` covers it, matching the boundary the
 * package draws for a "does not exist" fact (see CustomerNotFoundException). This is a
 * deliberate divergence from the reference, which 404s the invoice download at the HTTP
 * layer; here a missing invoice is a catchable billing fact, not a framework response.
 */
class InvoiceNotFoundException extends CashierException
{
    /**
     * Create the exception for a specific invoice identifier.
     */
    public static function withId(string $invoiceId): self
    {
        return new self("No invoice found for identifier [{$invoiceId}].");
    }
}
