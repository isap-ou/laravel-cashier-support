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
use Isapp\CashierSupport\Models\Concerns\DecidesAccess;
use Isapp\CashierSupport\Models\Concerns\ReportsStatus;
use Isapp\CashierSupport\Models\Concerns\TracksCancellation;
use Isapp\CashierSupport\Models\Concerns\TracksTrialPeriod;

/**
 * Abstract local record of a provider subscription.
 *
 * Holds the subscription state mirrored from the provider. It performs no
 * billing logic — lifecycle actions go through the Billable concerns and the
 * gateway provider. Concrete provider packages extend this model.
 *
 * **The predicates live in the traits below, each beside the query scope that must agree with
 * it** (#29). The grouping is one trait per column-family — the status, the trial date, the
 * cancellation date, and the access decision that weighs all three — which is Gateway\BaseGateway's
 * arrangement applied here: cohesive traits flattened into an abstract base, so every subclass
 * inherits them and no driver has to mix anything in. Pairing each predicate with its scope is
 * the point, not tidiness: the two answer the same question in two languages, and #29's whole
 * acceptance criterion is that they never disagree. Split across two files, drift is invisible.
 *
 * Unlike BaseGateway's Defaults\*, these carry no collision risk — nothing else defines these
 * methods — so the traits are the organising device and inheritance is merely how drivers get
 * them.
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
    use DecidesAccess;
    use HasUuids;
    use ReportsStatus;
    use TracksCancellation;
    use TracksTrialPeriod;

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
}
