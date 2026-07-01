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
     */
    public function __construct(
        public string $description,
        public int $amount,
        public int $quantity = 1,
    ) {}
}
