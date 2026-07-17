<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Models\Subscription;

/**
 * The trial period, both ways of asking.
 *
 * The first place where neither reference's scope body transfers. Stripe's onTrial() is a bare
 * date read (Subscription.php:365) and its scopeOnTrial is the matching bare date query (:377);
 * ours falls back to the STATUS when trial_ends_at is null, so scopeOnTrial has two arms and
 * mixes AND with OR where Stripe's does neither — which is why it groups its arms and Stripe's
 * needs no group at all. See scopeOnTrial() for what that grouping is, and is not, for.
 *
 * Composed into Models\Subscription — see its docblock before using this directly.
 *
 * @phpstan-require-extends Subscription
 */
trait TracksTrialPeriod
{
    /**
     * Whether the subscription is on trial. A stale Trialing status (webhook lag) does not
     * count once trial_ends_at is in the past.
     */
    public function onTrial(): bool
    {
        if ($this->trial_ends_at !== null) {
            return $this->trial_ends_at->isFuture();
        }

        return $this->status === SubscriptionStatus::Trialing;
    }

    /**
     * onTrial(), as a query.
     *
     * Two arms, because the predicate has two: the date decides whenever there is one, and the
     * status answers only in its absence. Stripe's scopeOnTrial (:377) is the first arm alone —
     * it has no status fallback to express.
     *
     * **Each arm is grouped; the pair is not.** Eloquent already isolates a scope's own
     * constraints — Builder::callScope() counts the wheres before and after the body and hands
     * the new ones to addNewWheresWithinGroup(), which rebuilds them as an isolated nested
     * group. So `->pastDue()->onTrial()` cannot leak, and a wrapper around the whole body would
     * only add a redundant layer of parentheses.
     *
     * What that does NOT do is parenthesise AND against OR *inside* one body, and this body has
     * both: `(date AND future) OR (no date AND trialing)`. Left flat, the arms would rebind to
     * `date AND (future OR no date) AND trialing`. The groups are that, and nothing to do with
     * the caller.
     *
     * `>` rather than `>=` mirrors isFuture(), which is strict: a trial_ends_at of exactly now
     * is not a future one.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeOnTrial(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
        })->orWhere(function (Builder $query): void {
            $query->whereNull('trial_ends_at')->where('status', SubscriptionStatus::Trialing);
        });
    }

    /**
     * Every subscription onTrial() answers false for.
     *
     * No matching predicate, and that asymmetry is the point: PHP has `!`, a query builder does
     * not, and a scope cannot be negated from outside — so this is the only way to express the
     * complement inside a whereHas() group.
     *
     * **Derived from scopeOnTrial, not mirrored by hand.** An earlier version spelled out De
     * Morgan's — `(no date OR not future) AND (date OR not trialing)` — which is the same
     * two-bodies-drift this trait's whole design refuses elsewhere: add an arm to scopeOnTrial
     * and the hand-derived complement is silently wrong, with only the 72-row matrix between
     * that and a customer.
     *
     * Safe to negate wholesale only because scopeOnTrial is null-EXPLICIT: every comparison it
     * makes sits behind whereNotNull/whereNull, so no NULL reaches a bare `>` for SQL's
     * three-valued logic to turn into UNKNOWN — where `NOT UNKNOWN` is UNKNOWN, and the row
     * silently vanishes from both a scope and its negation. A positive scope that compared a
     * nullable column directly could not be negated this way.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeNotOnTrial(Builder $query): void
    {
        $query->whereNot(function (Builder $query): void {
            $query->onTrial();
        });
    }
}
