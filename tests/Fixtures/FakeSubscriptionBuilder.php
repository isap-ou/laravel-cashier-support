<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use DateTimeInterface;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\SubscriptionStatus;

class FakeSubscriptionBuilder implements SubscriptionBuilder
{
    public function __construct(private readonly string $type) {}

    public function trialDays(int $days): static
    {
        return $this;
    }

    public function trialUntil(DateTimeInterface $date): static
    {
        return $this;
    }

    public function quantity(int $quantity): static
    {
        return $this;
    }

    public function withMetadata(array $metadata): static
    {
        return $this;
    }

    public function create(?string $paymentMethod = null, array $options = []): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $this->type, status: SubscriptionStatus::Active);
    }

    public function add(array $options = []): Subscription
    {
        return $this->create();
    }
}
