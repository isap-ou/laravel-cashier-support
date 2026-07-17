<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\Enums\RefundReason;
use Money\Currency;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Data;

/**
 * A refund of a previous payment.
 */
class Refund extends Data
{
    /**
     * @param  int  $amount  Refunded amount in minor units (cents).
     */
    public function __construct(
        public string $id,
        public string $paymentId,
        public int $amount,
        #[WithCastAndTransformer(CurrencyCast::class)]
        public Currency $currency,
        public ?RefundReason $reason = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
