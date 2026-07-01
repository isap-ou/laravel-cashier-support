<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * An invoice for one or more charges.
 */
class Invoice extends Data
{
    /**
     * @param  int  $amount  Invoice total in minor units (cents).
     * @param  array<int, InvoiceLine>  $lines
     */
    public function __construct(
        public string $id,
        public int $amount,
        public Currency $currency,
        public PaymentStatus $status,
        public ?string $number = null,
        #[DataCollectionOf(InvoiceLine::class)]
        public array $lines = [],
        public ?CarbonImmutable $issuedAt = null,
    ) {}
}
