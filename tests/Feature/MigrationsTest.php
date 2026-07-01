<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

class MigrationsTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Eager loading resolves relations on an unhydrated model, which
        // falls back to the default driver's registry entry.
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The abstract relations resolve concrete classes through the
        // per-driver registry — no fixture overrides mask that path.
        Cashier::useModels('fake', [
            'subscription' => ConcreteSubscription::class,
            'subscription_item' => ConcreteSubscriptionItem::class,
        ]);
    }

    public function test_subscription_schema_matches_the_model(): void
    {
        $subscription = ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
        ]);

        $subscription->items()->create([
            'provider' => 'fake',
            'provider_id' => 'si_ext',
            'price' => 'price_monthly',
            'quantity' => 2,
        ]);

        $this->assertTrue(Str::isUuid($subscription->getKey()));

        $fresh = ConcreteSubscription::query()->with('items')->find($subscription->getKey());

        $this->assertInstanceOf(SubscriptionStatus::class, $fresh->status);
        $this->assertTrue($fresh->active());
        $this->assertCount(1, $fresh->items);

        $item = $fresh->items->first();
        $this->assertInstanceOf(ConcreteSubscriptionItem::class, $item);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('price_monthly', $item->price);

        // Inverse relation resolves the concrete class via the registry too.
        $this->assertInstanceOf(ConcreteSubscription::class, $item->subscription()->first());
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
