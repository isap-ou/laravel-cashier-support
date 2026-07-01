<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\CheckoutMode;
use Spatie\LaravelData\Data;

/**
 * A hosted checkout session the customer is redirected to.
 */
class CheckoutSession extends Data
{
    public function __construct(
        public string $id,
        public string $url,
        public CheckoutMode $mode,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
