<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * When a pause takes effect.
 *
 * Like Enums\SwapTiming, the caller states its intent and the capability gate answers, rather
 * than the app inspecting the driver and branching. A gateway that can only pause immediately
 * cannot honour "pause at the end of the cycle", and one that can only defer cannot honour
 * "pause now" — each must say so plainly.
 *
 * **The default here is AtPeriodEnd, where SwapTiming's is Immediate — and the inversion is
 * deliberate.** SwapTiming defaults to Immediate because that is what Stripe and Paddle both do
 * for a swap. Pause is the opposite: Stripe does not wrap pausing at all (its raw pause_collection
 * is immediate-only and does not even change the status), so it offers no default to copy, and
 * Paddle — the only reference that pauses — defers by default. Its pause(bool $pauseNow = false)
 * (Subscription.php:734) sends `next_billing_period` unless asked for `immediately`, so the bare
 * verb is the deferred one and pauseNow() is the immediate variant, exactly as cancel()/cancelNow()
 * are. Defaulting to Immediate would invert that convention and make the no-argument call the
 * destructive one — access revoked on the click.
 */
enum PauseTiming: string
{
    /** The pause takes effect now. Paddle's pauseNow(); Stripe's pause_collection. */
    case Immediate = 'immediate';

    /** The pause takes effect when the current billing cycle ends. Paddle's default. */
    case AtPeriodEnd = 'at_period_end';

    /**
     * The capability a gateway must declare to honour this timing.
     */
    public function capability(): Capability
    {
        return match ($this) {
            self::Immediate => Capability::SubscriptionPauseImmediate,
            self::AtPeriodEnd => Capability::SubscriptionPauseAtPeriodEnd,
        };
    }
}
