<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Isapp\CashierSupport\Enums\Interval;
use Spatie\LaravelData\Data;

/**
 * A single priced item within a subscription.
 */
class SubscriptionItem extends Data
{
    public function __construct(
        public string $id,
        public string $price,
        public int $quantity = 1,
        public ?Interval $interval = null,
    ) {}
}
