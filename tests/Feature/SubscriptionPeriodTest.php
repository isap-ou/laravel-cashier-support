<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\BillingReason;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * A subscription has a period it is paid through, and an invoice pays for one.
 *
 * `ends_at` only ever said "when access stops", and only on cancellation — so a
 * live subscription could not answer "when am I next billed?", and a renewal
 * invoice could not say which subscription or which cycle it settled.
 */
class SubscriptionPeriodTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::useModels('fake', [
            'subscription' => ConcreteSubscription::class,
            'subscription_item' => ConcreteSubscriptionItem::class,
            'invoice' => ConcreteInvoice::class,
        ]);
    }

    private function subscription(): ConcreteSubscription
    {
        return ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
            'current_period_start' => CarbonImmutable::parse('2026-07-01T00:00:00Z'),
            'current_period_end' => CarbonImmutable::parse('2026-08-01T00:00:00Z'),
        ]);
    }

    public function test_a_live_subscription_knows_when_it_is_next_billed(): void
    {
        $subscription = $this->subscription()->fresh();

        $this->assertSame('2026-07-01T00:00:00+00:00', $subscription?->currentPeriodStart()?->toIso8601String());
        $this->assertSame('2026-08-01T00:00:00+00:00', $subscription?->currentPeriodEnd()?->toIso8601String());
        // The period is not the end of access: the subscription is alive.
        $this->assertNull($subscription?->ends_at);
        $this->assertTrue($subscription?->valid());
    }

    public function test_a_gateway_without_a_billing_cycle_leaves_the_period_unknown(): void
    {
        $subscription = ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_no_cycle',
            'status' => SubscriptionStatus::Active,
        ])->fresh();

        $this->assertNull($subscription?->currentPeriodEnd());
    }

    public function test_an_invoice_records_the_subscription_and_cycle_it_paid_for(): void
    {
        $subscription = $this->subscription();

        $invoice = ConcreteInvoice::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'provider' => 'fake',
            'provider_id' => 'in_ext',
            'amount' => 1500,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
            'subscription_id' => $subscription->getKey(),
            'period_start' => CarbonImmutable::parse('2026-07-01T00:00:00Z'),
            'period_end' => CarbonImmutable::parse('2026-08-01T00:00:00Z'),
            'billing_reason' => BillingReason::SubscriptionCycle,
        ]);

        $fresh = ConcreteInvoice::query()->findOrFail($invoice->getKey());

        $this->assertSame('2026-07-01T00:00:00+00:00', $fresh->period_start?->toIso8601String());
        $this->assertSame('2026-08-01T00:00:00+00:00', $fresh->period_end?->toIso8601String());
        $this->assertSame(BillingReason::SubscriptionCycle, $fresh->billing_reason);

        // The relation resolves the concrete class through the per-driver
        // registry, exactly as Subscription::items() does.
        $related = $fresh->subscription()->first();
        $this->assertInstanceOf(ConcreteSubscription::class, $related);
        $this->assertTrue($related->is($subscription));
    }

    public function test_an_invoice_that_pays_for_no_subscription_stays_unlinked(): void
    {
        $invoice = ConcreteInvoice::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'provider' => 'fake',
            'provider_id' => 'in_oneoff',
            'amount' => 500,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
            'billing_reason' => BillingReason::Manual,
        ])->fresh();

        $this->assertNull($invoice?->subscription()->first());
        $this->assertSame(BillingReason::Manual, $invoice?->billing_reason);
    }
}
