<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Builders;

use DateTimeInterface;
use InvalidArgumentException;
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
 *
 * @internal Returned by Gateway\GuardedProvider::newSubscription(); an app holds it as a Contracts\SubscriptionBuilder and never constructs it. That CONTRACT is public and covered; this wrapper is not.
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
     *
     * **Another guard the contract had already promised** — Contracts\SubscriptionBuilder:25
     * says "@throws InvalidArgumentException When the number of days is negative", and nothing
     * threw it. Third instance of the defect `.claude/rules/exceptions.md` was written about,
     * after charge() and quantity(): a guard that lives only in a docblock lets the caller's own
     * typo travel to the gateway, where it returns a 4xx and arrives as a *billing* failure the
     * app is invited to catch — inverting the boundary exactly.
     *
     * **Zero is legal here, and that is the deliberate difference from its neighbour.**
     * quantity(0) is refused because there is no such subscription — zero seats is a typo. Zero
     * trial days is an ordinary answer, and the expression that produces it is ordinary too:
     * `trialDays(max(0, $daysLeftOnTheOldPlan))`. Refusing it would make the guard do the
     * caller's arithmetic, forcing an `if` around a call that already means "no trial".
     *
     * @throws InvalidArgumentException When $days is negative.
     */
    public function trialDays(int $days): static
    {
        Cashier::ensureSupports(Capability::SubscriptionTrials, $this->driver);

        if ($days < 0) {
            throw new InvalidArgumentException(
                "A trial cannot run for a negative number of days, {$days} given. To start billing immediately, pass 0 or omit the trial."
            );
        }

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
     *
     * **The contract has promised this guard since it was written and nothing threw it** —
     * Contracts\SubscriptionBuilder:40 says "@throws InvalidArgumentException When the quantity
     * is not positive", and `->quantity(0)` sailed into the driver. That is not a new rule being
     * invented here; it is `.claude/rules/exceptions.md`'s "a declared guard must exist in code",
     * and the second instance of the exact defect that rule was written about the first time:
     * charge() documented the same throw for a non-positive amount and validated nothing, so the
     * caller's own bug travelled to the gateway, came back a 4xx, and arrived as a *billing*
     * failure the app is invited to swallow. Zero seats is a typo, and a typo must not be
     * catchable as a decline.
     *
     * Neither reference guards here, and that is worth stating rather than hiding: Stripe's
     * builder checks only WHICH price a quantity belongs to, never the value
     * (SubscriptionBuilder.php:154); Paddle's is a bare assignment (:44). Their silence is not
     * assent, and it does not outrank a promise this package already made to its callers.
     *
     * Stripe's `?int $quantity` is deliberately NOT copied along with the guard: null there means
     * "send no quantity", which is what a metered price needs and is the same idea our nullable
     * column carries as "unknown". The contract types this `int`, so that state is already
     * inexpressible on this builder — widening it to say so is a decision, not a guard, and not
     * this ticket's.
     *
     * @throws InvalidArgumentException When $quantity is below 1.
     */
    public function quantity(int $quantity): static
    {
        Cashier::ensureSupports(Capability::SubscriptionQuantity, $this->driver);

        if ($quantity < 1) {
            throw new InvalidArgumentException(
                "A subscription quantity must be at least 1, {$quantity} given. To sell no seats, do not subscribe."
            );
        }

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
