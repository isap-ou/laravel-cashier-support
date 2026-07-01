<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Subscription lifecycle for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait ManagesSubscriptions
{
    use InteractsWithProvider;

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
        $this->ensureCashierSupports(Capability::Subscriptions);

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
