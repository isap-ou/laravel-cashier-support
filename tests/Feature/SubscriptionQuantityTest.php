<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use InvalidArgumentException;
use Isapp\CashierSupport\DTO\SubscriptionItem as SubscriptionItemData;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\BaseGateway;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
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

    public function test_a_quantity_of_zero_cannot_be_sold_either(): void
    {
        // The contract has promised this since it was written — Contracts\SubscriptionBuilder:40
        // declares "@throws InvalidArgumentException When the quantity is not positive" — and
        // nothing threw it, so ->quantity(0) reached the driver. Second instance of the defect
        // .claude/rules/exceptions.md was written about ("a declared guard must exist in code",
        // citing charge() doing exactly this).
        //
        // Found only because the review of the MUTATION guard prompted looking at the setter:
        // refusing updateSubscriptionQuantity(0) while waving ->quantity(0) through is one
        // question answered two ways.
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantity]);

        try {
            (new User)->newSubscription('default', 'price_1')->quantity(0);
            $this->fail('Expected a zero quantity to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('0', $e->getMessage());
        }

        $this->assertNull($gateway->lastBuilder?->quantity, 'Nothing may reach the driver\'s builder.');
    }

    public function test_the_capability_gate_outranks_the_quantity_guard(): void
    {
        // Order matters and is not arbitrary: a gateway that bills no quantity at all must say
        // THAT, not quibble with the number. Otherwise an app fixing the "invalid quantity" it
        // was told about would arrive at the real answer — there is no quantity here — only on
        // the second try.
        $this->driverSupporting([Capability::Subscriptions]);

        $this->expectException(UnsupportedOperationException::class);

        (new User)->newSubscription('default', 'price_1')->quantity(0);
    }

    public function test_metadata_throws_when_the_provider_stores_none(): void
    {
        // The sibling of the quantity hole, and the last ungated method on the
        // builder: a gateway with nowhere to put metadata used to accept the call
        // and drop the data on the floor.
        $this->driverSupporting([Capability::Subscriptions]);

        $this->expectException(UnsupportedOperationException::class);
        (new User)->newSubscription('default', 'price_1')->withMetadata(['order_id' => '7']);
    }

    public function test_metadata_reaches_the_providers_builder_when_supported(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionMetadata]);

        (new User)->newSubscription('default', 'price_1')->withMetadata(['order_id' => '7'])->create();

        $this->assertSame(['order_id' => '7'], $gateway->lastBuilder?->metadata);
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

    /**
     * A billable with a stored subscription whose items are ['price' => quantity].
     *
     * @param  array<string, int|null>  $items
     */
    private function subscribedUser(array $items = ['price_monthly' => 3]): User
    {
        $user = new User(['id' => 1]);

        $subscription = ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
        ]);

        foreach ($items as $price => $quantity) {
            $subscription->items()->create([
                'provider' => 'fake',
                'price' => $price,
                'quantity' => $quantity,
            ]);
        }

        return $user;
    }

    public function test_a_seat_count_reaches_the_gateway(): void
    {
        // #37's acceptance criterion, and the whole point of the ticket: before this,
        // quantity could be set at creation and never again, so seat-based billing was
        // not buildable on this package at all.
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->subscribedUser()->updateSubscriptionQuantity('default', 5);

        $this->assertSame(5, $gateway->lastQuantity);
        $this->assertSame('default', $gateway->lastQuantityType);
        $this->assertSame(
            'price_monthly',
            $gateway->lastQuantityPrice,
            'The caller named no price, but a driver must never have to guess one: the concern '
            .'holds the local items, so it resolves the only one and names it.'
        );
    }

    public function test_an_absent_capability_refuses_naming_the_update_not_the_setter(): void
    {
        // Two capabilities, one word apart. Told the wrong one, an app would go looking for
        // a quantity concept the gateway has — it just cannot change it after the fact.
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantity]);

        try {
            $this->subscribedUser()->updateSubscriptionQuantity('default', 5);
            $this->fail('Expected the update to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertStringContainsString(Capability::SubscriptionQuantityUpdate->value, $e->getMessage());
            $this->assertStringNotContainsString(Capability::SubscriptionQuantity->value.' ', $e->getMessage());
        }
    }

    public function test_the_two_quantity_capabilities_come_apart_on_a_real_gateway(): void
    {
        // This one CANNOT go through FakeGateway, and that is the point. FakeGateway::supports()
        // is an in_array() over a hand-passed list and never reads Capability::methods(), so it
        // cannot notice the mutation this test exists to kill: folding updateSubscriptionQuantity
        // into Customers-style Capability::SubscriptionQuantity => [...]. BaseGateway derives
        // support from what was overridden, so only it can tell the two apart.
        //
        // The failure that would follow is silent: SubscriptionQuantity would stop being
        // intent-granular, declaredCapabilities() would no longer grant it, and every driver
        // that sets quantity at creation but has not written the mutation would lose the
        // BUILDER setter it has always had. #36 learned this the hard way — see the comment on
        // Capability::Customers.
        $gateway = new class extends BaseGateway
        {
            protected function declaredCapabilities(): array
            {
                return [Capability::SubscriptionQuantity];
            }
        };

        $this->assertTrue(
            $gateway->supports(Capability::SubscriptionQuantity),
            'A gateway that bills per seat at creation declares the builder setter, and must keep it.'
        );
        $this->assertFalse(
            $gateway->supports(Capability::SubscriptionQuantityUpdate),
            'It never wrote updateSubscriptionQuantity(), so it cannot claim the mutation.'
        );
    }

    public function test_increment_adds_to_what_is_stored(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->subscribedUser(['price_monthly' => 3])->incrementSubscriptionQuantity('default', 2);

        $this->assertSame(5, $gateway->lastQuantity, 'Increment is relative: 3 stored + 2 asked = 5 sent.');
    }

    public function test_decrement_floors_at_one(): void
    {
        // Both references floor here rather than let a decrement walk into zero
        // (Stripe Subscription.php:506, Paddle :522) — a zero-seat subscription is
        // a cancellation wearing the wrong method's name.
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->subscribedUser(['price_monthly' => 2])->decrementSubscriptionQuantity('default', 9);

        $this->assertSame(1, $gateway->lastQuantity);
    }

    public function test_a_decrement_can_never_raise_the_bill(): void
    {
        // Review found this one live, and it is the reason a review runs on a green suite:
        // max(1, 3 - -5) is 8, so `decrement by -5` on three seats silently billed for EIGHT.
        // The quantity-below-one guard cannot catch it — 8 sails through. Neither reference
        // guards $count either; both are wrong not to, and .claude/rules/exceptions.md decides
        // this rather than they do.
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        try {
            $this->subscribedUser(['price_monthly' => 3])->decrementSubscriptionQuantity('default', -5);
            $this->fail('Expected a negative decrement to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('-5', $e->getMessage(), 'The message must name what the CALLER passed.');
        }

        $this->assertNull($gateway->lastQuantity, 'Nothing may reach the gateway — least of all a raise.');
    }

    public function test_an_increment_refuses_a_negative_count_by_that_name(): void
    {
        // The mirror. This one already failed, but for the wrong reason and with the wrong
        // words: 3 + -5 = -2 tripped the quantity guard and reported "-2 given" — a number the
        // caller never typed, sending them to look at the wrong argument.
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        try {
            $this->subscribedUser(['price_monthly' => 3])->incrementSubscriptionQuantity('default', -5);
            $this->fail('Expected a negative increment to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('-5', $e->getMessage());
            $this->assertStringNotContainsString('-2', $e->getMessage(), 'Do not report arithmetic the caller never did.');
        }
    }

    public function test_an_unknown_quantity_cannot_be_incremented(): void
    {
        // The edge neither reference has, because neither stores a nullable quantity.
        // Ours does, and null means "unknown" (Models\SubscriptionItem) — so there is
        // nothing to add to, and inventing 0 + 1 = 1 would invent a seat count.
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->expectException(CashierException::class);
        $this->expectExceptionMessage('[price_monthly]');

        $this->subscribedUser(['price_monthly' => null])->incrementSubscriptionQuantity('default');
    }

    public function test_an_unknown_quantity_can_still_be_set_outright(): void
    {
        // The counterpart: not knowing where you are does not stop you naming where to go.
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->subscribedUser(['price_monthly' => null])->updateSubscriptionQuantity('default', 4);

        $this->assertSame(4, $gateway->lastQuantity);
    }

    public function test_a_quantity_below_one_is_the_callers_bug(): void
    {
        // Not a CashierException: the gateway never sees it. Paddle throws here too
        // (Subscription.php:535, 'Quantities of zero are not allowed.').
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->expectException(InvalidArgumentException::class);

        $this->subscribedUser()->updateSubscriptionQuantity('default', 0);
    }

    public function test_several_prices_need_the_caller_to_name_one(): void
    {
        // Both references guard (Stripe guardAgainstMultiplePrices() :1547, Paddle
        // singleItemOrFail() :103). Without it, "set the quantity to 5" on a two-price
        // subscription has no answer, and picking one silently bills the wrong line.
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->expectException(InvalidArgumentException::class);

        $this->subscribedUser(['price_monthly' => 1, 'price_seats' => 2])
            ->updateSubscriptionQuantity('default', 5);
    }

    public function test_a_named_price_selects_the_item_among_several(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionQuantityUpdate]);

        $this->subscribedUser(['price_monthly' => 1, 'price_seats' => 2])
            ->incrementSubscriptionQuantity('default', 3, 'price_seats');

        $this->assertSame(5, $gateway->lastQuantity, 'Increment must read price_seats (2), not price_monthly (1).');
        $this->assertSame('price_seats', $gateway->lastQuantityPrice);
    }
}
