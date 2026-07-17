<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\Enums\BillingReason;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Money\Currency;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Data;

/**
 * An invoice for one or more charges.
 */
class Invoice extends Data
{
    /**
     * @param  int  $amount  Invoice total in minor units (cents). This is the canonical total;
     *                       when the breakdown is present, subtotal + tax - discount reconciles to it.
     * @param  array<int, InvoiceLine>  $lines
     * @param  int|null  $subtotal  Total before tax and discount, in minor units (cents); null when not broken down.
     * @param  int|null  $tax  Aggregate tax across the invoice, in minor units (cents); null when not broken down.
     * @param  int|null  $discount  Aggregate discount across the invoice, in minor units (cents); null when none.
     */
    public function __construct(
        public string $id,
        public int $amount,
        #[WithCastAndTransformer(CurrencyCast::class)]
        public Currency $currency,
        public PaymentStatus $status,
        public ?string $number = null,
        #[DataCollectionOf(InvoiceLine::class)]
        public array $lines = [],
        public ?CarbonImmutable $issuedAt = null,
        public ?string $subscriptionId = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public ?BillingReason $billingReason = null,
        public ?int $subtotal = null,
        public ?int $tax = null,
        public ?int $discount = null,
    ) {}
}
