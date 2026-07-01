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
        ];
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
     * record's provider column.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        $provider = $this->getAttribute('provider');

        return $this->hasMany(Cashier::subscriptionItemModel(is_string($provider) ? $provider : null), 'subscription_id');
    }

    /**
     * Whether the subscription is currently active or trialing.
     */
    public function active(): bool
    {
        return $this->status->isActive() && ! $this->hasEnded();
    }

    /**
     * Whether the subscription has been canceled.
     */
    public function canceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled || $this->ends_at !== null;
    }

    /**
     * Whether the subscription is on trial.
     */
    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            || ($this->trial_ends_at !== null && $this->trial_ends_at->isFuture());
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
