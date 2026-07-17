<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Subscription;

/**
 * Who gets served — the two access predicates, and the same two as queries.
 *
 * The package's hottest question, and the one #25 renamed: valid() is the access question and
 * what subscribed() asks; active() is the narrow one that also wants the renewal to have gone
 * through. Both weigh the dates against the status, and both apply the app's dunning policy
 * through statusesGrantingAccess() below.
 *
 * Composed into Models\Subscription — see its docblock before using this directly.
 *
 * @phpstan-require-extends Subscription
 */
trait DecidesAccess
{
    /**
     * Whether the subscription grants access: active, trialing, or canceled but still within its
     * paid-through grace period (the customer paid until ends_at).
     *
     * This is the access question, and the one to ask. Both references route their Billable
     * helpers through valid() and never through active()
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
     * valid(), as a query.
     *
     * The predicate's three clauses in the same order: not ended, not denied outright, and then
     * any one of the three things that can carry access.
     *
     * **The one scope here whose group is load-bearing**, and the only one worth reading twice.
     * Eloquent isolates a scope's constraints from the caller's on its own —
     * Builder::callScope() hands whatever the body added to addNewWheresWithinGroup() — so no
     * scope needs a wrapper to defend itself from what it is chained to. What that machinery
     * does not do is parenthesise AND against OR *within* one body, and this body has both.
     * Flattened, the arms would rebind:
     *
     *     notEnded AND status NOT IN (denied) OR onTrial OR onGracePeriod   -- wrong
     *     notEnded AND status NOT IN (denied) AND (grants OR onTrial OR onGracePeriod)
     *
     * The first hands access to every trialing or in-grace row regardless of the two guards
     * above it — including the unpaid and incomplete_expired rows #22 exists to refuse. The
     * group is what keeps the guards guarding.
     *
     * Stripe has no scopeValid at all. Paddle's (:183) is `status = trialing OR status = active`,
     * plus past_due when the toggle allows — one flat OR chain, which needs no group, and which
     * reads neither date.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->notEnded()
            ->whereNotIn('status', $this->statusesDenyingAccess())
            ->where(function (Builder $query): void {
                $query->whereIn('status', $this->statusesGrantingAccess())
                    ->orWhere(function (Builder $query): void {
                        $query->onTrial();
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->onGracePeriod();
                    });
            });
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
     * active(), as a query.
     *
     * The predicate exactly: its two clauses, in its order, reading the same list.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->notEnded()->whereIn('status', $this->statusesGrantingAccess());
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
     * nothing else.
     */
    private function statusGrantsAccess(): bool
    {
        return $this->statusGrants($this->status);
    }

    /**
     * The dunning policy itself: one status in, one answer out, and the only body that decides.
     *
     * Both callers reach the same match through here — the predicate asks it about one status,
     * statusesGrantingAccess() asks it about all eight — so the two cannot drift, and neither
     * pays for the other. Asking the LIST this question and then searching it would have made
     * valid() allocate and filter an eight-element array per call, on a predicate whose
     * neighbouring split exists to avoid evaluating hasEnded() twice; the parameter is what buys
     * the scope its list without charging the hot path for it.
     *
     * Encodes the two gated arms of Stripe's active(): Subscription.php:232 for incomplete,
     * :234 for past_due.
     */
    private function statusGrants(SubscriptionStatus $status): bool
    {
        return match ($status) {
            SubscriptionStatus::PastDue => ! Cashier::deactivatesPastDue(),
            SubscriptionStatus::Incomplete => ! Cashier::deactivatesIncomplete(),
            default => $status->isActive(),
        };
    }

    /**
     * Every status that grants access under the app's current dunning policy.
     *
     * The whole reason the scopes can be trusted. A predicate and a scope that each spell out
     * their own status list are two bodies maintained by hand, and the references show what
     * that costs: Stripe's active() (:229-236) and scopeActive() (:240-256) are written
     * separately, both status-NEGATIVE, and both correct only until the enum grows a bad status
     * they forgot to list. Ours has one — Paused — and a negative list would report it active.
     *
     * So the list is every case that statusGrants() says yes to — the same body the predicate
     * asks about one status. The predicate and the scope cannot disagree, because there is only
     * one body to disagree with.
     *
     * Never empty — Active and Trialing are unconditional, so no toggle can empty the whereIn.
     *
     * @return list<SubscriptionStatus>
     */
    private function statusesGrantingAccess(): array
    {
        return array_values(array_filter(SubscriptionStatus::cases(), $this->statusGrants(...)));
    }

    /**
     * Every status that withholds access on its own, whatever the dates say.
     *
     * The same trick against SubscriptionStatus::deniesAccess(), for valid()'s second guard.
     * Unlike the list above this one answers to no toggle — that is what deniesAccess() means.
     *
     * @return list<SubscriptionStatus>
     */
    private function statusesDenyingAccess(): array
    {
        return array_values(array_filter(
            SubscriptionStatus::cases(),
            fn (SubscriptionStatus $status): bool => $status->deniesAccess(),
        ));
    }
}
