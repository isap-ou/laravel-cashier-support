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
    /**
     * @param  int|null  $quantity  Null when the gateway has no per-subscription
     *                              quantity, or will not report one back. Never
     *                              read it as zero or one: it means "unknown".
     */
    public function __construct(
        public string $id,
        public string $price,
        public ?int $quantity = null,
        public ?Interval $interval = null,
    ) {}
}
