<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PauseTiming;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\SubscriptionOperations, refused.
 *
 * The largest of these by far, and the reason the refusals are grouped by contract at all:
 * six operations behind five different capabilities, where pause, resume and cancel-now are
 * each separately absent from real gateways (Revolut has none of the three).
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 */
trait RefusesSubscriptions
{
    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder
    {
        throw UnsupportedOperationException::forCapability(Capability::Subscriptions);
    }

    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::Subscriptions);
    }

    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionCancelNow);
    }

    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionResume);
    }

    /**
     * The refusal names the timing the caller asked for, not "pause".
     *
     * Timing is not a detail of a pause, it IS the pause (Enums\PauseTiming): a gateway that
     * can only pause immediately must refuse "pause at cycle end" by that name, or the app is
     * told the wrong thing about why it failed. The mapping lives on PauseTiming, so this reads
     * it rather than re-deriving it.
     */
    public function pauseSubscription(
        Model $billable,
        string $type = 'default',
        PauseTiming $timing = PauseTiming::AtPeriodEnd,
        ?DateTimeInterface $until = null,
    ): Subscription {
        throw UnsupportedOperationException::forCapability($timing->capability());
    }

    /**
     * The refusal names the timing the caller asked for, not "swap".
     *
     * Timing is not a detail of a swap, it IS the swap (Enums\SwapTiming): a gateway that
     * can only defer must refuse "upgrade me now" by that name, or the app is told the wrong
     * thing about why it failed.
     */
    public function swapSubscription(Model $billable, string $type, string|array $prices, SwapTiming $timing = SwapTiming::Immediate, array $options = []): Subscription
    {
        throw UnsupportedOperationException::forCapability(
            $timing === SwapTiming::Immediate
                ? Capability::SubscriptionSwapImmediate
                : Capability::SubscriptionSwapAtPeriodEnd
        );
    }

    /**
     * SubscriptionQuantityUpdate, not SubscriptionQuantity: a gateway that bills per seat at
     * creation but cannot restate the count later supports the second and refuses this one.
     */
    public function updateSubscriptionQuantity(Model $billable, string $type, int $quantity, string $price): Subscription
    {
        throw UnsupportedOperationException::forCapability(Capability::SubscriptionQuantityUpdate);
    }
}
