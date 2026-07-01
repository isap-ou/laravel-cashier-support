<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\RefundReason;
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
        public Currency $currency,
        public ?RefundReason $reason = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
