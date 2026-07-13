<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * When a plan change takes effect.
 *
 * The caller states its intent and the capability gate answers — rather than the
 * app inspecting the driver and branching. A gateway that can only defer must
 * say so, and an app that needs the upgrade to apply *now* must be told plainly
 * that it cannot, instead of silently receiving a change that lands next month.
 */
enum SwapTiming: string
{
    /** The plan changes now. Stripe and Paddle both work this way. */
    case Immediate = 'immediate';

    /** The plan changes when the current billing cycle ends. Revolut only works this way. */
    case AtPeriodEnd = 'at_period_end';

    /**
     * The capability a gateway must declare to honour this timing.
     */
    public function capability(): Capability
    {
        return match ($this) {
            self::Immediate => Capability::SubscriptionSwapImmediate,
            self::AtPeriodEnd => Capability::SubscriptionSwapAtPeriodEnd,
        };
    }
}
