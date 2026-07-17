<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use Isapp\CashierSupport\Builders\GuardedSubscriptionBuilder;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\SubscriptionUpdateFailure;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Subscription as SubscriptionRecord;
use Isapp\CashierSupport\Models\SubscriptionItem as SubscriptionItemRecord;

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
    // Subscriptions are where tax rates are consumed (create and swap), so this
    // concern carries the tax surface rather than requiring it of every other
    // concern — ManagesCustomer and friends stay usable on their own.
    use HandlesTaxes;
    use InteractsWithProvider;

    /**
     * All local subscription records of this entity for its driver.
     *
     * Scoped by provider: the table is shared between drivers, so records
     * written by another gateway must never leak into this driver's view.
     *
     * @return MorphMany<SubscriptionRecord, $this>
     */
    public function subscriptions(): MorphMany
    {
        $driver = $this->cashierDriver() ?? Cashier::getDefaultDriver();

        return $this->morphMany(Cashier::subscriptionModel($driver), 'owner')
            ->where('provider', $driver)
            ->latest();
    }

    /**
     * The entity's local subscription record of the given type, if any.
     *
     * Reads the already-loaded relation when available (supports eager
     * loading) and falls back to a query otherwise.
     */
    public function subscription(string $type = 'default'): ?SubscriptionRecord
    {
        if ($this->relationLoaded('subscriptions')) {
            /** @var SubscriptionRecord|null */
            return $this->getRelation('subscriptions')->firstWhere('type', $type);
        }

        /** @var SubscriptionRecord|null */
        return $this->subscriptions()->where('type', $type)->first();
    }

    /**
     * Whether the entity has an active subscription of the type (optionally
     * narrowed to a specific price identifier).
     */
    public function subscribed(string $type = 'default', ?string $price = null): bool
    {
        if ($price !== null) {
            return $this->subscribedToPrice($price, $type);
        }

        return (bool) $this->subscription($type)?->valid();
    }

    /**
     * Whether the entity's subscription of the given type is on trial
     * (optionally narrowed to a specific price identifier).
     */
    public function onTrial(string $type = 'default', ?string $price = null): bool
    {
        $subscription = $this->subscription($type);

        if ($subscription === null || ! $subscription->onTrial()) {
            return false;
        }

        return $price === null || $subscription->items()->where('price', $price)->exists();
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

        if ($subscription === null || ! $subscription->valid()) {
            return false;
        }

        return $subscription->items()->whereIn('price', (array) $prices)->exists();
    }

    /**
     * Begin creating a new subscription of the given type.
     *
     * The provider's builder is wrapped so the capabilities it exposes — a
     * trial, today — are gated like every other operation, instead of being
     * silently dropped by a provider that cannot honour them.
     *
     * @param  string|array<int, string>  $prices
     */
    public function newSubscription(string $type, string|array $prices): SubscriptionBuilder
    {
        $this->ensureCashierSupports(Capability::Subscriptions);
        $this->ensureTaxRatesSupported();

        return new GuardedSubscriptionBuilder(
            $this->cashierProvider()->newSubscription($this, $type, $prices),
            $this->cashierDriver(),
        );
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
    public function swapSubscription(
        string $type,
        string|array $prices,
        SwapTiming $timing = SwapTiming::Immediate,
        array $options = [],
    ): Subscription {
        // The intent is gated, not the operation: a gateway that only defers
        // cannot honour Immediate, and must say so rather than quietly giving
        // the caller a change that lands next month.
        $this->ensureCashierSupports($timing->capability());
        $this->ensureTaxRatesSupported();

        return $this->cashierProvider()->swapSubscription($this, $type, $prices, $timing, $options);
    }

    /**
     * Set how many of a subscription's price the entity is billed for.
     *
     * The absolute form: $quantity is the number to land on. Both references make this the
     * only call a gateway ever sees, with increment and decrement composed on top
     * (Stripe Subscription.php:518, Paddle :532).
     *
     * @param  string  $type  The subscription type.
     * @param  int  $quantity  The new quantity, which must be at least 1.
     * @param  string|null  $price  Which item to change; omit when the subscription has one.
     *
     * @throws UnsupportedOperationException When the provider cannot change a quantity.
     * @throws SubscriptionUpdateFailure When there is no such subscription, or no such price on it.
     * @throws InvalidArgumentException When $quantity is below 1, or the subscription has
     *                                  several prices and none was named.
     */
    public function updateSubscriptionQuantity(string $type, int $quantity, ?string $price = null): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionQuantityUpdate);

        $this->ensureCashierQuantityIsPositive($quantity);

        // Resolved for its guards, not its value: this is what refuses an ambiguous call
        // before a gateway has to guess which line to bill.
        $item = $this->cashierQuantityItem($type, $price);

        return $this->cashierSetQuantity($type, $quantity, $item);
    }

    /**
     * Bill for $count more of a subscription's price than it is billed for now.
     *
     * @param  string  $type  The subscription type.
     * @param  int  $count  How many to add, at least 1.
     * @param  string|null  $price  Which item to change; omit when the subscription has one.
     *
     * @throws UnsupportedOperationException When the provider cannot change a quantity.
     * @throws SubscriptionUpdateFailure When there is no such subscription or price, or the
     *                                   current quantity is unknown.
     * @throws InvalidArgumentException When $count is below 1, or the subscription has several
     *                                  prices and none was named.
     */
    public function incrementSubscriptionQuantity(string $type = 'default', int $count = 1, ?string $price = null): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionQuantityUpdate);

        $this->ensureCashierCountIsPositive($count, 'increment');

        $item = $this->cashierQuantityItem($type, $price);

        return $this->cashierSetQuantity($type, $this->cashierKnownQuantity($item) + $count, $item);
    }

    /**
     * Bill for $count fewer of a subscription's price than it is billed for now.
     *
     * Floors at 1, as both references do (Stripe Subscription.php:506, Paddle :522): a
     * decrement that walked into zero would be a cancellation nobody asked for.
     *
     * @param  string  $type  The subscription type.
     * @param  int  $count  How many to remove, at least 1.
     * @param  string|null  $price  Which item to change; omit when the subscription has one.
     *
     * @throws UnsupportedOperationException When the provider cannot change a quantity.
     * @throws SubscriptionUpdateFailure When there is no such subscription or price, or the
     *                                   current quantity is unknown.
     * @throws InvalidArgumentException When $count is below 1, or the subscription has several
     *                                  prices and none was named.
     */
    public function decrementSubscriptionQuantity(string $type = 'default', int $count = 1, ?string $price = null): Subscription
    {
        $this->ensureCashierSupports(Capability::SubscriptionQuantityUpdate);

        $this->ensureCashierCountIsPositive($count, 'decrement');

        $item = $this->cashierQuantityItem($type, $price);

        return $this->cashierSetQuantity(
            $type,
            max(1, $this->cashierKnownQuantity($item) - $count),
            $item,
        );
    }

    /**
     * Refuse a relative change that is not one.
     *
     * Neither reference guards this, and both are wrong not to: `decrementQuantity(-5)` on a
     * subscription billed for 3 sends `max(1, 3 - -5)` = 8, so a method named *decrement*
     * silently RAISES the bill to eight seats. The `$quantity < 1` guard cannot catch it — 8
     * passes. Increment is the mirror: `incrementQuantity(-5)` on 3 computes -2 and then fails
     * naming -2, a number the caller never typed.
     *
     * Not deference to the references, because .claude/rules/exceptions.md decides this and not
     * they do: a negative count to "add some" is a malformed argument, which is the caller's bug
     * to fix rather than a billing failure to catch. Direction is what these methods are FOR;
     * an argument that reverses it is not a quantity, it is a typo.
     *
     * @throws InvalidArgumentException When $count is below 1.
     */
    private function ensureCashierCountIsPositive(int $count, string $verb): void
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                "A quantity to {$verb} by must be at least 1, {$count} given. To move the other way, "
                .($verb === 'increment' ? 'decrement' : 'increment').' instead.'
            );
        }
    }

    /**
     * Send an already-resolved absolute quantity to the gateway.
     *
     * Split out so increment and decrement do not resolve the item and re-check the gate a
     * second time by calling the public method: they have both already done exactly that, and
     * seat changes are the hot path this whole ticket exists to enable.
     *
     * @throws UnsupportedOperationException When the provider cannot change a quantity.
     * @throws InvalidArgumentException When $quantity is below 1.
     */
    private function cashierSetQuantity(string $type, int $quantity, SubscriptionItemRecord $item): Subscription
    {
        $this->ensureCashierQuantityIsPositive($quantity);

        return $this->cashierProvider()->updateSubscriptionQuantity($this, $type, $quantity, $item->price);
    }

    /**
     * Refuse a quantity a subscription cannot hold.
     *
     * Paddle refuses this too ("Quantities of zero are not allowed.", Subscription.php:535).
     * Not a CashierException: the gateway never sees the call, so there is no billing failure
     * to report — only a bug to fix. Zero seats is a cancellation, and it has its own method.
     *
     * @throws InvalidArgumentException When $quantity is below 1.
     */
    private function ensureCashierQuantityIsPositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException(
                "A subscription quantity must be at least 1, {$quantity} given. To end a subscription, cancel it."
            );
        }
    }

    /**
     * The local item a quantity change is about.
     *
     * $price may be omitted only when there is exactly one item — both references guard the
     * same way (Stripe guardAgainstMultiplePrices() :1543, Paddle singleItemOrFail() :99),
     * because "set the quantity to 5" on a subscription billed on two prices has no answer,
     * and picking one silently bills the wrong line.
     *
     * The ambiguity test is Paddle's (count the items) rather than Stripe's, which asks
     * whether its own `stripe_price` column is null — a column of a gateway's own schema, and
     * not a shape this package has or wants.
     *
     * @throws SubscriptionUpdateFailure When there is no such subscription, or no such price on it.
     * @throws InvalidArgumentException When several prices are billed and none was named.
     */
    private function cashierQuantityItem(string $type, ?string $price): SubscriptionItemRecord
    {
        $subscription = $this->subscription($type);

        if ($subscription === null) {
            throw new SubscriptionUpdateFailure("There is no [{$type}] subscription to change the quantity of.");
        }

        $items = $subscription->items()->get();

        if ($price === null) {
            if ($items->count() > 1) {
                throw new InvalidArgumentException(
                    "The [{$type}] subscription is billed on several prices, so one must be named to change its quantity."
                );
            }

            $item = $items->first();
        } else {
            $item = $items->firstWhere('price', $price);
        }

        if ($item === null) {
            throw new SubscriptionUpdateFailure(
                $price === null
                    ? "The [{$type}] subscription has no priced items to change the quantity of."
                    : "The [{$type}] subscription is not billed on [{$price}]."
            );
        }

        return $item;
    }

    /**
     * The item's current quantity, when a relative change needs one to build on.
     *
     * An edge neither reference has, because neither stores a nullable quantity: here null
     * means "unknown", never zero and never one (Models\SubscriptionItem), so a gateway with
     * no quantity concept can write the row honestly. There is then nothing for an increment
     * to add to — and treating unknown as 0 would invent a seat count and bill it. Absolute
     * updateSubscriptionQuantity() is unaffected: not knowing where you are does not stop you
     * naming where to go.
     *
     * @throws SubscriptionUpdateFailure When the quantity is unknown.
     */
    private function cashierKnownQuantity(SubscriptionItemRecord $item): int
    {
        if ($item->quantity === null) {
            throw new SubscriptionUpdateFailure(
                "The quantity billed for [{$item->price}] is unknown, so it cannot be raised or lowered. Set it outright instead."
            );
        }

        return $item->quantity;
    }

    /**
     * When the entity's trial of the given type ends, if it is on one.
     *
     * **Narrower than both references, because the concept they fall back to does not exist
     * here.** Theirs answer for a "generic" trial — one held before any subscription exists —
     * first, and only then read the subscription (Stripe ManagesSubscriptions.php:118, Paddle
     * :109). That trial is stored somewhere we have nothing equivalent to, and the two
     * references do not even agree where: Stripe on the billable's own table, Paddle on the
     * customer row. So this reads the subscription and nothing else. Not a different answer to
     * the same question — a narrower question, until generic trials have a home of their own.
     */
    public function trialEndsAt(string $type = 'default'): ?CarbonImmutable
    {
        return $this->subscription($type)?->trial_ends_at;
    }
}
