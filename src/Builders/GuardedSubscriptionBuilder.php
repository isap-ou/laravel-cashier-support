<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Builders;

use DateTimeInterface;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Wraps a provider's subscription builder and gates the capabilities the
 * builder itself exposes.
 *
 * The gate lives here rather than in each driver on purpose: a driver cannot
 * forget to declare a trial unsupported, because the check is not its to make.
 * Every other capability is already gated in the Billable concerns; the builder
 * was the one surface that escaped, so trialDays()/trialUntil() would silently
 * do nothing on a provider without trials.
 */
final class GuardedSubscriptionBuilder implements SubscriptionBuilder
{
    /**
     * Not readonly: the contract returns `static`, so a driver is free to
     * implement its builder immutably and hand back a modified clone. Keeping
     * the returned instance is what makes that legal — discarding it would
     * silently drop every setting, which is the very failure this class exists
     * to prevent.
     */
    private SubscriptionBuilder $builder;

    public function __construct(
        SubscriptionBuilder $builder,
        private readonly ?string $driver = null,
    ) {
        $this->builder = $builder;
    }

    /**
     * {@inheritDoc}
     */
    public function trialDays(int $days): static
    {
        Cashier::ensureSupports(Capability::SubscriptionTrials, $this->driver);

        $this->builder = $this->builder->trialDays($days);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function trialUntil(DateTimeInterface $date): static
    {
        Cashier::ensureSupports(Capability::SubscriptionTrials, $this->driver);

        $this->builder = $this->builder->trialUntil($date);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function quantity(int $quantity): static
    {
        Cashier::ensureSupports(Capability::SubscriptionQuantity, $this->driver);

        $this->builder = $this->builder->quantity($quantity);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withMetadata(array $metadata): static
    {
        $this->builder = $this->builder->withMetadata($metadata);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function create(?string $paymentMethod = null, array $options = []): Subscription
    {
        return $this->builder->create($paymentMethod, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function add(array $options = []): Subscription
    {
        return $this->builder->add($options);
    }
}
