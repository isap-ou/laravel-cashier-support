<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Isapp\CashierSupport\Models\Subscription;

/**
 * The pause, and both ways of asking about it — the paused_at column.
 *
 * paused_at carries two questions the way ends_at does in TracksCancellation: it is the instant
 * the pause takes effect, so a paused_at in the FUTURE is a scheduled pause the subscription is
 * still serving under (onPausedGracePeriod), and a paused_at in the PAST is a pause already in
 * force (paused). One column, split by tense — mirroring Paddle, which writes the real pause
 * instant for an immediate pause and the scheduled effective_at for a deferred one into that one
 * column and then discriminates with isFuture() (Subscription.php:346).
 *
 * paused() reads paused_at, NOT the status, deliberately. The references disagree on where the
 * paused fact lives — Paddle moves the status to `paused` (Subscription.php:314), Stripe's
 * pause_collection leaves the status untouched — so a status check would answer for one gateway
 * and not the other. The column is the fact both can report, which is what an abstraction needs.
 *
 * Every scope here is null-EXPLICIT (whereNotNull before any bare `<`/`>`), for the reason
 * TracksCancellation::scopeNotEnded() spells out: SQL's `NOT UNKNOWN` is UNKNOWN, so a positive
 * scope that compared a nullable column directly would drop its NULL rows from both itself and its
 * negation. The negations are derived from the positives via whereNot, never hand-mirrored.
 *
 * Composed into Models\Subscription — see its docblock before using this directly.
 *
 * @phpstan-require-extends Subscription
 */
trait TracksPause
{
    /**
     * Whether the subscription is paused now.
     *
     * A pause already in effect: paused_at is set and in the past. A pause scheduled for the end
     * of the cycle (paused_at in the future) is NOT this — it is onPausedGracePeriod(), and the
     * subscription is still serving.
     */
    public function paused(): bool
    {
        return $this->paused_at !== null && $this->paused_at->isPast();
    }

    /**
     * paused(), as a query.
     *
     * `<` mirrors isPast(), which is strict — a row whose paused_at is exactly now is neither
     * paused nor on the paused grace period, and both scopes agree with both predicates on that.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopePaused(Builder $query): void
    {
        $query->whereNotNull('paused_at')->where('paused_at', '<', Carbon::now());
    }

    /**
     * Every subscription paused() answers false for.
     *
     * Derived, not mirrored — see TracksCancellation::scopeNotEnded() for why, and why it is only
     * safe because scopePaused is null-explicit.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeNotPaused(Builder $query): void
    {
        $query->whereNot(function (Builder $query): void {
            $query->paused();
        });
    }

    /**
     * Whether a pause is scheduled but has not taken effect yet.
     *
     * The paused grace period: paused_at is set and in the future, so the subscription keeps
     * serving until then. Named after Paddle's onPausedGracePeriod() (Subscription.php:346),
     * which is `paused_at && paused_at->isFuture()` — the same body.
     */
    public function onPausedGracePeriod(): bool
    {
        return $this->paused_at !== null && $this->paused_at->isFuture();
    }

    /**
     * onPausedGracePeriod(), as a query.
     *
     * `>` mirrors isFuture(), which is strict. Mirrors
     * vendor/laravel/cashier-paddle/src/Subscription.php:357.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeOnPausedGracePeriod(Builder $query): void
    {
        $query->whereNotNull('paused_at')->where('paused_at', '>', Carbon::now());
    }

    /**
     * Every subscription onPausedGracePeriod() answers false for.
     *
     * Derived, not mirrored — see TracksCancellation::scopeNotEnded().
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeNotOnPausedGracePeriod(Builder $query): void
    {
        $query->whereNot(function (Builder $query): void {
            $query->onPausedGracePeriod();
        });
    }
}
