<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\DTO\SubscriptionItem as SubscriptionItemData;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * Quantity is a gateway-specific attribute, not a universal one: some gateways
 * carry no per-subscription quantity at all, and cannot report one back. NULL
 * therefore means "unknown / not applicable" — never zero, never one.
 *
 * Storing it as NOT NULL forced drivers to either invent a value (billing a
 * five-seat subscription as one) or refuse to write the item row at all.
 */
class SubscriptionQuantityTest extends TestCase
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
        ]);
    }

    /**
     * @param  array<int, Capability>  $capabilities
     */
    private function driverSupporting(array $capabilities): FakeGateway
    {
        $gateway = new FakeGateway($capabilities);

        Cashier::extend('fake', fn () => $gateway);

        return $gateway;
    }

    public function test_quantity_throws_when_the_provider_has_no_quantity_concept(): void
    {
        $this->driverSupporting([Capability::Subscriptions]);

        $this->expectException(UnsupportedOperationException::class);
        (new User)->newSubscription('default', 'price_1')->quantity(5);
    }

    public function test_quantity_reaches_the_providers_builder_when_supported(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantity]);

        (new User)->newSubscription('default', 'price_1')->quantity(5)->create();

        $this->assertSame(5, $gateway->lastBuilder?->quantity);
    }

    public function test_an_item_row_persists_an_unknown_quantity_as_null(): void
    {
        // The payoff: a driver whose gateway has no quantity can now write the
        // item row honestly, instead of inventing a 1 or writing nothing.
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
            'price' => 'price_monthly',
            'quantity' => null,
        ]);

        $item = $subscription->items()->firstOrFail();

        $this->assertNull($item->quantity);
        $this->assertSame('price_monthly', $item->price);
    }

    public function test_the_dto_defaults_quantity_to_null(): void
    {
        $item = new SubscriptionItemData(id: 'si_1', price: 'price_monthly');

        $this->assertNull($item->quantity);
        $this->assertNull($item->toArray()['quantity']);
    }
}
