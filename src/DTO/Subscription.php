<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * A subscription at the gateway provider.
 */
class Subscription extends Data
{
    /**
     * @param  array<int, SubscriptionItem>  $items
     */
    /**
     * @param  array<int, SubscriptionItem>  $items
     * @param  CarbonImmutable|null  $endsAt  When access stops (cancellation).
     * @param  CarbonImmutable|null  $currentPeriodEnd  Paid through — and, while
     *                                                  the subscription is live,
     *                                                  the next charge date. Null
     *                                                  when the gateway exposes no
     *                                                  billing cycle.
     */
    public function __construct(
        public string $id,
        public string $type,
        public SubscriptionStatus $status,
        #[DataCollectionOf(SubscriptionItem::class)]
        public array $items = [],
        public ?CarbonImmutable $trialEndsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $currentPeriodStart = null,
        public ?CarbonImmutable $currentPeriodEnd = null,
    ) {}
}
