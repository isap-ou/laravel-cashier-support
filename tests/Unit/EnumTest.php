<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PauseTiming;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\RefundReason;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Tests\TestCase;

class EnumTest extends TestCase
{
    public function test_user_facing_enum_labels_resolve_from_package_translations(): void
    {
        $this->assertSame('Pending', PaymentStatus::Pending->getLabel());
        $this->assertSame('Requires payment method', PaymentStatus::RequiresPaymentMethod->getLabel());
        $this->assertSame('Requires confirmation', PaymentStatus::RequiresConfirmation->getLabel());
        $this->assertSame('Requires action', PaymentStatus::RequiresAction->getLabel());
        $this->assertSame('Succeeded', PaymentStatus::Succeeded->getLabel());
        $this->assertSame('Past due', SubscriptionStatus::PastDue->getLabel());
        $this->assertSame('Unpaid', SubscriptionStatus::Unpaid->getLabel());
        $this->assertSame('Incomplete expired', SubscriptionStatus::IncompleteExpired->getLabel());
        $this->assertSame('Requested by customer', RefundReason::RequestedByCustomer->getLabel());
    }

    public function test_get_labels_returns_all_cases_keyed_by_name(): void
    {
        $labels = PaymentStatus::getLabels();

        $this->assertSame('Pending', $labels->get('Pending'));
        $this->assertCount(count(PaymentStatus::cases()), $labels);
    }

    public function test_interact_with_collection_values(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'pending',
                'requires_payment_method',
                'requires_confirmation',
                'requires_action',
                'processing',
                'succeeded',
                'failed',
                'canceled',
                'refunded',
            ],
            PaymentStatus::values()->all(),
        );
    }

    public function test_backed_values(): void
    {
        $this->assertSame('past_due', SubscriptionStatus::PastDue->value);
        $this->assertSame('unpaid', SubscriptionStatus::Unpaid->value);
        $this->assertSame('incomplete_expired', SubscriptionStatus::IncompleteExpired->value);
        $this->assertTrue(SubscriptionStatus::Active->isActive());
        $this->assertTrue(SubscriptionStatus::Trialing->isActive());
        $this->assertFalse(SubscriptionStatus::Canceled->isActive());
        $this->assertFalse(SubscriptionStatus::Unpaid->isActive());
        $this->assertFalse(SubscriptionStatus::IncompleteExpired->isActive());
    }

    /**
     * The pause timing routes to the capability a gateway must declare — the single mapping
     * RefusesSubscriptions and ManagesSubscriptions both read, so it is pinned here rather than
     * left to be re-derived at each call site.
     */
    public function test_pause_timing_maps_to_its_capability(): void
    {
        $this->assertSame(Capability::SubscriptionPauseImmediate, PauseTiming::Immediate->capability());
        $this->assertSame(Capability::SubscriptionPauseAtPeriodEnd, PauseTiming::AtPeriodEnd->capability());
    }

    /**
     * Swept over cases() rather than listed, so a status added later has to
     * state which side of the access line it falls on instead of defaulting
     * quietly to "grants access".
     */
    public function test_only_the_unrecoverable_statuses_deny_access(): void
    {
        $denying = array_filter(
            SubscriptionStatus::cases(),
            fn (SubscriptionStatus $status): bool => $status->deniesAccess(),
        );

        $this->assertEqualsCanonicalizing(
            [SubscriptionStatus::Unpaid, SubscriptionStatus::IncompleteExpired],
            array_values($denying),
        );
    }
}
