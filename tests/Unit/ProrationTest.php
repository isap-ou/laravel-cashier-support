<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\Proration;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * Proration is a caller-intent enum in the shape of SwapTiming, but its capability mapping is
 * deliberately asymmetric: the prorated default both references ship is the baseline, so it is
 * ungated, and only "do not prorate" names a capability a gateway may lack. See docs/specs/
 * subscription-proration.md.
 */
class ProrationTest extends TestCase
{
    public function test_only_the_non_default_intent_carries_a_capability(): void
    {
        // Prorate is the ungated baseline — a plain swap or quantity change must not newly refuse
        // on a gateway that has not modelled proration at all.
        $this->assertNull(Proration::Prorate->capability());

        // NoProrate is the intent a gateway can silently drop, so it is the one that gates.
        $this->assertSame(Capability::SubscriptionNoProration, Proration::NoProrate->capability());
    }

    public function test_backed_values_are_intent_words_not_gateway_vocabulary(): void
    {
        // Neither string appears in Stripe's {none, create_prorations, always_invoice} nor Paddle's
        // {prorated_next_billing_period, full_next_billing_period, …}: no wire vocabulary in src/.
        $this->assertSame('prorate', Proration::Prorate->value);
        $this->assertSame('no_prorate', Proration::NoProrate->value);
    }
}
