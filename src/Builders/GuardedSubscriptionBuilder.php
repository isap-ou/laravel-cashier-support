<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Builders;

use DateTimeInterface;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Wraps a provider's subscription builder and gates every capability the builder
 * exposes: trials, quantity, metadata.
 *
 * The gate lives here rather than in each driver on purpose: a driver cannot
 * forget to declare a feature unsupported, because the check is not its to make.
 * The builder was the one surface that escaped the gating in the Billable
 * concerns, and each ungated setter behaved the same way — it accepted the call
 * and the value went nowhere. A trial that does not trial, a quantity that is
 * not billed, metadata a gateway has nowhere to put: silence, and data on the
 * floor.
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
        Cashier::ensureSupports(Capability::SubscriptionMetadata, $this->driver);

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
