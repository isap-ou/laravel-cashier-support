<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * Whether a mid-cycle change is prorated.
 *
 * The caller states its intent and the capability gate answers — the same shape as
 * Enums\SwapTiming. The references disagree on everything below this one axis (Stripe folds
 * "invoice now" into a third value, Paddle spreads prorated/full × now/next-period plus a
 * suppress-billing opt-out), and their shared vocabulary is a false friend: `noProrate()` means
 * "suppress the proration" in Stripe and "bill the full amount next period" in Paddle. So this
 * carries only the intent both express — prorate, or do not — and none of either gateway's wire
 * words. What "do not prorate" then does is the gateway's to decide, faithfully; the abstraction
 * carries the intent, not the mechanism.
 */
enum Proration: string
{
    /** Prorate the change against the current period. Both references default to a prorated behaviour. */
    case Prorate = 'prorate';

    /** Apply the change without prorating the partial period. */
    case NoProrate = 'no_prorate';

    /**
     * The capability a gateway must declare to honour this intent, or null when none is needed.
     *
     * Unlike SwapTiming, whose every case maps to a capability, the default here is *ungated*:
     * proration is the baseline both references already do, so a plain `Prorate` must not newly
     * refuse a swap or a quantity change on a gateway that has not modelled proration at all. Only
     * `NoProrate` is an intent a gateway can silently drop — prorating against the caller's word —
     * so only `NoProrate` carries the gate.
     */
    public function capability(): ?Capability
    {
        return match ($this) {
            self::Prorate => null,
            self::NoProrate => Capability::SubscriptionNoProration,
        };
    }
}
