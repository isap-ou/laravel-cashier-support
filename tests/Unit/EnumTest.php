<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\RefundReason;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Tests\TestCase;

class EnumTest extends TestCase
{
    public function test_user_facing_enum_labels_resolve_from_package_translations(): void
    {
        $this->assertSame('Pending', PaymentStatus::Pending->getLabel());
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
            ['pending', 'processing', 'succeeded', 'failed', 'canceled', 'refunded'],
            PaymentStatus::values()->all(),
        );
    }

    public function test_currency_minor_units(): void
    {
        $this->assertSame(2, Currency::EUR->minorUnits());
        $this->assertSame(0, Currency::JPY->minorUnits());
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
