<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Testing;

use DateTimeInterface;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\SubscriptionStatus;

/**
 * Records what it was told, so a decorator wrapping it cannot silently drop a
 * setting and still look correct.
 */
class FakeSubscriptionBuilder implements SubscriptionBuilder
{
    public ?int $trialDays = null;

    public ?DateTimeInterface $trialUntil = null;

    public ?int $quantity = null;

    /**
     * @var array<string, mixed>
     */
    public array $metadata = [];

    public ?string $paymentMethod = null;

    /**
     * The gateway that made this builder, so create()/add() can record the subscription
     * it produced onto the gateway's spy — the only place a test can prove creation
     * happened, since newSubscription() only hands back a builder.
     */
    public function __construct(private readonly FakeGateway $gateway, private readonly string $type) {}

    public function trialDays(int $days): static
    {
        $this->trialDays = $days;

        return $this;
    }

    public function trialUntil(DateTimeInterface $date): static
    {
        $this->trialUntil = $date;

        return $this;
    }

    public function quantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function withMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function create(?string $paymentMethod = null, array $options = []): Subscription
    {
        $this->paymentMethod = $paymentMethod;

        $subscription = new Subscription(id: 'sub_fake', type: $this->type, status: SubscriptionStatus::Active);

        $this->gateway->createdSubscriptions[] = $subscription;

        return $subscription;
    }

    public function add(array $options = []): Subscription
    {
        return $this->create();
    }
}
