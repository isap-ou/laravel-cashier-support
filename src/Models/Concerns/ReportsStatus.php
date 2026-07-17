<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Models\Subscription;

/**
 * What the status reports about itself — never what an app is allowed to do about it.
 *
 * The dunning statuses, both ways of asking. These two predicates report the STATUS and stop
 * there: a subscription does not stop being past due because an app chose to keep serving it.
 * The access policy that reads the same column lives in DecidesAccess, and the split is
 * deliberate — #56's hasIncompletePayment() composes exactly these two, and would otherwise
 * answer that a customer with a failed payment has no failed payment.
 *
 * Composed into Models\Subscription — see its docblock before using this directly.
 *
 * @phpstan-require-extends Subscription
 */
trait ReportsStatus
{
    /**
     * Whether the renewal payment failed and dunning is still running.
     *
     * Reports the status, not the access policy. Mirrors
     * vendor/laravel/cashier/src/Subscription.php:208 and
     * vendor/laravel/cashier-paddle/src/Subscription.php:293.
     */
    public function pastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    /**
     * pastDue(), as a query.
     *
     * Mirrors vendor/laravel/cashier/src/Subscription.php:219 and
     * vendor/laravel/cashier-paddle/src/Subscription.php:304.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopePastDue(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::PastDue);
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
     * incomplete(), as a query.
     *
     * Mirrors vendor/laravel/cashier/src/Subscription.php:198. Paddle has no equivalent, having
     * no such status.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeIncomplete(Builder $query): void
    {
        $query->where('status', SubscriptionStatus::Incomplete);
    }
}
