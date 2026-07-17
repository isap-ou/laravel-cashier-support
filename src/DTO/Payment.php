<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Money\Currency;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;
use Spatie\LaravelData\Data;

/**
 * A single payment / charge.
 */
class Payment extends Data
{
    /**
     * @param  int  $amount  Amount in minor units (cents).
     */
    public function __construct(
        public string $id,
        public int $amount,
        #[WithCastAndTransformer(CurrencyCast::class)]
        public Currency $currency,
        public PaymentStatus $status,
        public ?string $paymentMethodId = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
