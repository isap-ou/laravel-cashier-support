<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Spatie\LaravelData\Data;

/**
 * A single line item on an invoice.
 */
class InvoiceLine extends Data
{
    /**
     * @param  int  $amount  Line total in minor units (cents).
     * @param  int|null  $unitAmount  Price of a single unit in minor units (cents); null when unknown.
     * @param  int|null  $taxAmount  Tax charged on this line in minor units (cents); null when the line carries no tax.
     * @param  int|null  $taxRate  Tax rate in basis points (2000 = 20.00%); null when the line carries no tax.
     */
    public function __construct(
        public string $description,
        public int $amount,
        public int $quantity = 1,
        public ?int $unitAmount = null,
        public ?int $taxAmount = null,
        public ?int $taxRate = null,
    ) {}
}
