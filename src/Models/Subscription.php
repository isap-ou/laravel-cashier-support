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
 * @property string|null $next_price
 * @property CarbonImmutable|null $next_price_starts_at
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
            'next_price_starts_at' => 'immutable_datetime',
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
     * Whether a price change has been scheduled and has not taken effect yet.
     *
     * The subscription is still billed on the current price — items() names it,
     * and subscribedToPrice() keeps answering for it — until the change lands.
     */
    public function hasPendingPriceChange(): bool
    {
        return $this->next_price !== null;
    }

    /**
     * The price the subscription will move to, once the scheduled change lands.
     */
    public function pendingPrice(): ?string
    {
        return $this->next_price;
    }

    /**
     * When the scheduled change takes effect — normally the end of the current
     * billing period.
     *
     * Null while a change is pending means the gateway scheduled it without
     * saying when: unknown date, not absent change.
     */
    public function pendingPriceStartsAt(): ?CarbonImmutable
    {
        return $this->next_price_starts_at;
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
     * Whether the subscription grants access: active, trialing, or canceled but still
     * within its paid-through grace period (the customer paid until ends_at).
     *
     * This is the access question, and the one to ask. Both references route their
     * Billable helpers through valid() and never through active()
     * (vendor/laravel/cashier/src/Concerns/ManagesSubscriptions.php:142, :196, :220;
     * vendor/laravel/cashier-paddle/src/Concerns/ManagesSubscriptions.php:137, :155, :179),
     * and Concerns\ManagesSubscriptions::subscribed() does the same.
     *
     * NARROWER than Stripe's valid() (Subscription.php:177-180), on purpose. Stripe composes
     * `active() || onTrial() || onGracePeriod()` with no guard above it, so an unpaid
     * subscription that is on trial or in grace comes back valid — the paid-through date
     * outranks the fact that the money never arrived. #22 decided the opposite here, and
     * SubscriptionStatus::deniesAccess() encodes it: a status that withholds access on its
     * own is not a policy anyone gets to configure.
     *
     * The hasEnded() guard is this method's own and cannot be delegated to active(): the
     * onTrial() arm below is a trial_ends_at read, and a subscription whose ends_at has
     * passed can still carry a future trial date. Without the guard that date would talk
     * over the end of access.
     */
    public function valid(): bool
    {
        if ($this->hasEnded() || $this->status->deniesAccess()) {
            return false;
        }

        return $this->statusGrantsAccess() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Whether the subscription is currently active — narrower than valid(), and the one to
     * ask to deny a customer whose renewal failed even though they paid through ends_at.
     *
     * The references disagree on this body, so it is a decision rather than a port. Stripe
     * (Subscription.php:229-236) is status-NEGATIVE — "not ended, and not one of these four
     * bad statuses" — which it can afford because it has no paused status; copied verbatim
     * it would report a paused subscription active on the strength of not being listed.
     * Paddle (:251-254) is status-POSITIVE, `status === active`, but parks the past_due
     * toggle in valid() instead, so flipping it could never restore active().
     *
     * So: Stripe's placement, our exclusion set. The two dunning statuses are answered by
     * policy and everything else by the status itself — and the policy has to live in
     * statusGrantsAccess() rather than in isActive(), because a predicate that required
     * isActive() could never report past_due active, which would leave $deactivatePastDue
     * nothing to turn on.
     *
     * No deniesAccess() guard, unlike valid(): unpaid and incomplete_expired are not
     * isActive(), so statusGrantsAccess() already answers false for both and the guard could
     * never change an answer. It earns its place in valid() only because the onTrial() and
     * onGracePeriod() arms there are date reads that would otherwise talk over the status.
     */
    public function active(): bool
    {
        return ! $this->hasEnded() && $this->statusGrantsAccess();
    }

    /**
     * Whether the STATUS alone grants access, once the app's dunning policy is applied —
     * the half of active() that knows nothing about dates.
     *
     * Split out because valid() and active() both need it and both own a different date
     * guard: composing valid() out of active() instead made every valid() call evaluate
     * hasEnded() twice, on the package's hottest predicate.
     *
     * Coined rather than borrowed: the references have no equivalent, because they have no
     * such split — Stripe folds the dates into active() itself (Subscription.php:231, the
     * `! $this->ended() &&` arm) and Paddle's active() (:251-254) reads the status and
     * nothing else. It encodes the two gated arms of Stripe's active(): :232 for incomplete,
     * :234 for past_due.
     */
    private function statusGrantsAccess(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::PastDue => ! Cashier::deactivatesPastDue(),
            SubscriptionStatus::Incomplete => ! Cashier::deactivatesIncomplete(),
            default => $this->status->isActive(),
        };
    }

    /**
     * Whether the renewal payment failed and dunning is still running.
     *
     * Reports the status, not the access policy: a subscription does not stop being past due
     * because an app chose to keep serving it. Mirrors
     * vendor/laravel/cashier/src/Subscription.php:208 and
     * vendor/laravel/cashier-paddle/src/Subscription.php:293.
     */
    public function pastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    /**
     * Whether the initial payment has not been completed yet.
     *
     * Mirrors vendor/laravel/cashier/src/Subscription.php:187. Paddle has no such status.
     */
    public function incomplete(): bool
    {
        return $this->status === SubscriptionStatus::Incomplete;
    }

    /**
     * Whether the subscription carries more than one price.
     *
     * Counts items, which is Paddle's body (Subscription.php:126) and the only one
     * expressible here: Stripe asks whether its own per-subscription stripe_price column is
     * null (:114), and we have no such column — every price lives in an item.
     */
    public function hasMultiplePrices(): bool
    {
        return ($this->relationLoaded('items') ? $this->items->count() : $this->items()->count()) > 1;
    }

    /**
     * Whether the subscription carries exactly one price.
     *
     * Mirrors vendor/laravel/cashier/src/Subscription.php:124 and
     * vendor/laravel/cashier-paddle/src/Subscription.php:136 — identical in both.
     */
    public function hasSinglePrice(): bool
    {
        return ! $this->hasMultiplePrices();
    }

    /**
     * Whether the subscription carries the given price identifier.
     *
     * Mirrors vendor/laravel/cashier/src/Subscription.php:148 and
     * vendor/laravel/cashier-paddle/src/Subscription.php:160.
     *
     * Reads the loaded collection when there is one and queries otherwise — like every
     * predicate here. Both references only ever read `$this->items`, so on a subscription
     * fetched with('items') they answer for free; querying unconditionally would have turned
     * a with('items') loop into one round-trip per row, which is the N+1 that eager loading
     * exists to prevent. The query path still matters: a lazily-read predicate must not drag
     * every item into memory to answer a boolean.
     */
    public function hasPrice(string $price): bool
    {
        if ($this->relationLoaded('items')) {
            return $this->items->contains(fn (SubscriptionItem $item): bool => $item->price === $price);
        }

        return $this->items()->where('price', $price)->exists();
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
