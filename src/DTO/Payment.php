<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
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
        public Currency $currency,
        public PaymentStatus $status,
        public ?string $paymentMethodId = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
