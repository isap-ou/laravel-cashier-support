<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Support\Str;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

class MigrationsTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    public function test_subscription_schema_matches_the_model(): void
    {
        $subscription = ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'name' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
        ]);

        $subscription->items()->create([
            'provider_id' => 'si_ext',
            'price' => 'price_monthly',
            'quantity' => 2,
        ]);

        $this->assertTrue(Str::isUuid($subscription->getKey()));

        $fresh = ConcreteSubscription::query()->with('items')->find($subscription->getKey());

        $this->assertInstanceOf(SubscriptionStatus::class, $fresh->status);
        $this->assertTrue($fresh->active());
        $this->assertCount(1, $fresh->items);
        $this->assertSame(2, $fresh->items->first()->quantity);
        $this->assertSame('price_monthly', $fresh->items->first()->price);
    }

    public function test_invoice_schema_matches_the_model(): void
    {
        $invoice = ConcreteInvoice::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'number' => '2026-001',
            'amount' => 1500,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);

        $fresh = ConcreteInvoice::query()->find($invoice->getKey());

        $this->assertSame(1500, $fresh->amount);
        $this->assertSame(Currency::EUR, $fresh->currency);
        $this->assertSame(PaymentStatus::Succeeded, $fresh->status);
        $this->assertTrue($fresh->paid());
    }
}
