<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Abstract local record of a provider subscription.
 *
 * Holds the subscription state mirrored from the provider. It performs no
 * billing logic — lifecycle actions go through the Billable concerns and the
 * gateway provider. Concrete provider packages extend this model.
 *
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 */
abstract class Subscription extends Model
{
    use HasUuids;

    protected $table = 'cashier_subscriptions';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
        ];
    }

    /**
     * When the current billing period started.
     *
     * Null when the gateway exposes no billing cycle, or has not reported one
     * yet — "unknown", not "none".
     */
    public function currentPeriodStart(): ?CarbonImmutable
    {
        return $this->current_period_start;
    }

    /**
     * When the current billing period ends: the paid-through date, and the date
     * of the next charge while the subscription is live.
     *
     * Distinct from ends_at, which says when *access* stops and is only set once
     * the subscription is cancelled. On cancellation a driver sets
     * ends_at = current_period_end — the customer paid through the cycle.
     */
    public function currentPeriodEnd(): ?CarbonImmutable
    {
        return $this->current_period_end;
    }

    /**
     * The billable entity that owns the subscription.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The items belonging to the subscription.
     *
     * Resolved per driver via the CashierManager model registry, using this
     * record's provider column. Note: eager loading (with('items')) resolves
     * relations on an unhydrated model, so it falls back to the DEFAULT
     * driver's class — lazy access on hydrated records is driver-exact.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        $provider = $this->getAttribute('provider');

        return $this->hasMany(Cashier::subscriptionItemModel(is_string($provider) ? $provider : null), 'subscription_id');
    }

    /**
     * Whether the subscription grants access: active, trialing, or canceled
     * but still within its paid-through grace period (Stripe Cashier
     * semantics — the customer paid until ends_at).
     */
    public function active(): bool
    {
        if ($this->hasEnded()) {
            return false;
        }

        return $this->status->isActive() || $this->onGracePeriod();
    }

    /**
     * Whether the subscription has been canceled.
     */
    public function canceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled || $this->ends_at !== null;
    }

    /**
     * Whether the subscription is on trial. A stale Trialing status (webhook
     * lag) does not count once trial_ends_at is in the past.
     */
    public function onTrial(): bool
    {
        if ($this->trial_ends_at !== null) {
            return $this->trial_ends_at->isFuture();
        }

        return $this->status === SubscriptionStatus::Trialing;
    }

    /**
     * Whether the subscription is within its grace period (canceled but not yet ended).
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }

    /**
     * Whether the subscription has fully ended.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }
}
