<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Subscription as SubscriptionRecord;

/**
 * Subscription lifecycle for a billable model.
 *
 * Mutations delegate to the gateway provider; the query-side methods
 * (subscription(), subscribed(), onTrial(), ...) read the local subscription
 * records kept in sync by the driver.
 *
 * @phpstan-require-extends Model
 */
trait ManagesSubscriptions
{
    use InteractsWithProvider;

    /**
     * All local subscription records of this entity for its driver.
     *
     * @return MorphMany<SubscriptionRecord, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Cashier::subscriptionModel($this->cashierDriver()), 'owner')->latest();
    }

    /**
     * The entity's local subscription record of the given type, if any.
     */
    public function subscription(string $type = 'default'): ?SubscriptionRecord
    {
        /** @var SubscriptionRecord|null */
        return $this->subscriptions()->where('name', $type)->first();
    }

    /**
     * Whether the entity has an active (or trialing) subscription of the type.
     */
    public function subscribed(string $type = 'default'): bool
    {
        return (bool) $this->subscription($type)?->active();
    }

    /**
     * Whether the entity's subscription of the given type is on trial.
     */
    public function onTrial(string $type = 'default'): bool
    {
        return (bool) $this->subscription($type)?->onTrial();
    }

    /**
     * Whether the entity's subscription of the given type is within its
     * cancellation grace period.
     */
    public function onGracePeriod(string $type = 'default'): bool
    {
        return (bool) $this->subscription($type)?->onGracePeriod();
    }

    /**
     * Whether the entity is subscribed to any of the given price identifiers.
     *
     * @param  string|array<int, string>  $prices
     */
    public function subscribedToPrice(string|array $prices, string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if ($subscription === null || ! $subscription->active()) {
            return false;
        }

        return $subscription->items()->whereIn('price', (array) $prices)->exists();
    }

    /**
     * Begin creating a new subscription of the given type.
     *
     * @param  string|array<int, string>  $prices
     */
    public function newSubscription(string $type, string|array $prices): SubscriptionBuilder
    {
        $this->ensureCashierSupports(Capability::Subscriptions);

        return $this->cashierProvider()->newSubscription($this, $type, $prices);
    }

    /**
     * Cancel a subscription at period end.
     */
    public function cancelSubscription(string $type = 'default'): Subscription
    {
        $this->ensureCashierSupports(Capability::Subscriptions);

        return $this->cashierProvider()->cancelSubscription($this, $type);
    }

    /**
     * Cancel a subscription immediately.
     */
    public function cancelSubscriptionNow(string $type = 'default'): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionCancelNow);

        return $this->cashierProvider()->cancelSubscriptionNow($this, $type);
    }

    /**
     * Resume a subscription within its grace period.
     */
    public function resumeSubscription(string $type = 'default'): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionResume);

        return $this->cashierProvider()->resumeSubscription($this, $type);
    }

    /**
     * Pause an active subscription.
     */
    public function pauseSubscription(string $type = 'default'): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionPause);

        return $this->cashierProvider()->pauseSubscription($this, $type);
    }

    /**
     * Swap a subscription to one or more new prices.
     *
     * @param  string|array<int, string>  $prices
     * @param  array<string, mixed>  $options
     */
    public function swapSubscription(string $type, string|array $prices, array $options = []): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionSwap);

        return $this->cashierProvider()->swapSubscription($this, $type, $prices, $options);
    }
}
