<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Models\Subscription;

/**
 * Cancellation and what follows it — the ends_at column, every way of asking it.
 *
 * ends_at carries three distinct questions, which is why they share a trait: it is written only
 * on cancellation (canceled), it is a paid-through date while it is in the future
 * (onGracePeriod), and it is the end of access once it is in the past (hasEnded). One column,
 * three answers, and each of them needs the other two to stay consistent.
 *
 * Every scope here is a single chain of one operator, so none carries an explicit group:
 * Builder::callScope() already isolates a scope's constraints from the caller's
 * (addNewWheresWithinGroup), and there is no internal AND/OR mix to parenthesise — unlike
 * TracksTrialPeriod's, and unlike DecidesAccess::scopeValid().
 *
 * Composed into Models\Subscription — see its docblock before using this directly.
 *
 * @phpstan-require-extends Subscription
 */
trait TracksCancellation
{
    /**
     * Whether the subscription has been canceled.
     */
    public function canceled(): bool
    {
        return $this->status === SubscriptionStatus::Canceled || $this->ends_at !== null;
    }

    /**
     * canceled(), as a query.
     *
     * Two arms, where Stripe's scopeCanceled (:314) has one: its canceled() is
     * `! is_null($this->ends_at)` and nothing else (:305), while ours also accepts the status on
     * its own — a gateway may report the cancellation before it reports the date.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeCanceled(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Canceled)->orWhereNotNull('ends_at');
    }

    /**
     * Every subscription canceled() answers false for.
     *
     * Derived from scopeCanceled rather than mirrored — see scopeNotEnded() for why the three
     * negations here are written this way, and when that would stop being safe.
     *
     * Emphatically not `where('status', '!=', Canceled)`: that alone would return every
     * canceled-and-dated row whose status a gateway has not caught up on yet.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeNotCanceled(Builder $query): void
    {
        $query->whereNot(function (Builder $query): void {
            $query->canceled();
        });
    }

    /**
     * Whether the subscription is within its grace period (canceled but not yet ended).
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isFuture();
    }

    /**
     * onGracePeriod(), as a query.
     *
     * Mirrors vendor/laravel/cashier/src/Subscription.php:420 and
     * vendor/laravel/cashier-paddle/src/Subscription.php:421. `>` mirrors isFuture(), which is
     * strict.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeOnGracePeriod(Builder $query): void
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    /**
     * Every subscription onGracePeriod() answers false for.
     *
     * Stripe spells this one out (:431); we derive it — see scopeNotEnded().
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeNotOnGracePeriod(Builder $query): void
    {
        $query->whereNot(function (Builder $query): void {
            $query->onGracePeriod();
        });
    }

    /**
     * Whether the subscription has fully ended.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * hasEnded(), as a query.
     *
     * `<` mirrors isPast(), which is strict — so a row whose ends_at is exactly now is neither
     * ended nor on grace period, and both scopes agree with both predicates about that.
     *
     * Stripe composes its scopeEnded out of `canceled()->notOnGracePeriod()` (:346), which
     * resolves to `ends_at IS NOT NULL AND ends_at <= now` — inclusive at that instant, where
     * its own ended() predicate (:333) is not. We do not inherit the discrepancy.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->whereNotNull('ends_at')->where('ends_at', '<', Carbon::now());
    }

    /**
     * Every subscription hasEnded() answers false for.
     *
     * No reference equivalent — Stripe ships no scopeNotEnded — but scopeActive and scopeValid
     * both need the complement of ended, and this is the one place to keep it.
     *
     * **The negations here are derived, not mirrored**, and this is the docblock the other two
     * point at. Writing out the inverse by hand — `ends_at IS NULL OR ends_at >= now`, as an
     * earlier version did — is the same two-bodies-drift that statusesGrantingAccess() exists to
     * kill: change the positive scope's boundary and the hand-written inverse quietly overlaps
     * or gaps it, and a row is then both ended and not ended, or neither.
     *
     * Negating a whole scope is safe here only because every positive scope in this trait is
     * null-EXPLICIT — each comparison sits behind whereNotNull, so no NULL reaches a bare
     * `<`/`>`. That matters: in SQL a comparison against NULL is UNKNOWN, and `NOT UNKNOWN` is
     * UNKNOWN, not TRUE — so a positive scope that compared a nullable column directly would
     * lose its NULL rows from BOTH itself and its negation. `ends_at IS NOT NULL AND ends_at < ?`
     * evaluates to FALSE for a null row, and NOT FALSE is TRUE, which is the answer hasEnded()
     * gives. The 72-row matrix covers every null combination, so this is pinned rather than
     * reasoned.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeNotEnded(Builder $query): void
    {
        $query->whereNot(function (Builder $query): void {
            $query->ended();
        });
    }
}
