<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;

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
        $this->assertTrue($fresh->valid());
        $this->assertCount(1, $fresh->items);

        $item = $fresh->items->first();
        $this->assertInstanceOf(ConcreteSubscriptionItem::class, $item);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('price_monthly', $item->price);

        // Inverse relation resolves the concrete class via the registry too.
        $this->assertInstanceOf(ConcreteSubscription::class, $item->subscription()->first());
    }

    public function test_a_price_cannot_be_billed_twice_on_one_subscription(): void
    {
        $subscription = ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
        ]);

        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_monthly']);

        // The table is shared by every driver, and a driver that misses the row
        // on a redelivered webhook would insert a second one. Nothing downstream
        // notices: subscribedToPrice() (ManagesSubscriptions.php:118) just sees
        // the price twice and still returns true, so the duplicate is silent.
        try {
            $subscription->items()->create(['provider' => 'fake', 'price' => 'price_monthly']);
            $this->fail('The second insert was accepted — the unique key is gone.');
        } catch (QueryException $e) {
            // Naming the constraint, not just the type. QueryException is any
            // database error, so asserting the type alone would stay green if
            // the unique key vanished and some unrelated schema fault took its
            // place — the exact way an assertion can pass while the invariant
            // it is named for does not hold.
            //
            // SQLSTATE 23000 is the portable half (integrity constraint
            // violation, every driver); the wording below is SQLite's, which is
            // what this suite runs on — testbench defaults to
            // env('DB_CONNECTION', 'sqlite') and neither phpunit.xml nor CI
            // sets it. Pointing DB_CONNECTION at MySQL would need the message
            // half rewritten ("Duplicate entry ... for key ...").
            $this->assertSame('23000', $e->getCode());
            $this->assertStringContainsStringIgnoringCase('unique constraint failed', $e->getMessage());
            $this->assertStringContainsString('subscription_id', $e->getMessage());
            $this->assertStringContainsString('price', $e->getMessage());
        }
    }

    public function test_a_subscription_may_still_carry_several_distinct_prices(): void
    {
        $subscription = ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
        ]);

        // The constraint is on the pair, not on subscription_id. A multi-item
        // subscription is the normal case for a Stripe-shaped driver, and the
        // unique key must not be the thing that forbids it.
        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_monthly']);
        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_seats']);

        $this->assertCount(2, $subscription->refresh()->items);
    }

    public function test_the_same_price_may_be_billed_on_two_different_subscriptions(): void
    {
        $prices = [];

        foreach (['sub_a', 'sub_b'] as $providerId) {
            $subscription = ConcreteSubscription::create([
                'owner_type' => User::class,
                'owner_id' => 1,
                'type' => $providerId,
                'provider' => 'fake',
                'provider_id' => $providerId,
                'status' => SubscriptionStatus::Active,
            ]);

            $subscription->items()->create(['provider' => 'fake', 'price' => 'price_monthly']);

            $prices[] = $subscription->refresh()->items->pluck('price')->all();
        }

        // Two customers on the same plan is not a conflict.
        $this->assertSame([['price_monthly'], ['price_monthly']], $prices);
    }

    public function test_invoice_schema_matches_the_model(): void
    {
        $invoice = ConcreteInvoice::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'number' => '2026-001',
            'amount' => 1500,
            'currency' => new Currency('EUR'),
            'status' => PaymentStatus::Succeeded,
        ]);

        $fresh = ConcreteInvoice::query()->find($invoice->getKey());

        $this->assertSame(1500, $fresh->amount);
        $this->assertSame('EUR', $fresh->currency->getCode());
        $this->assertSame(PaymentStatus::Succeeded, $fresh->status);
        $this->assertTrue($fresh->paid());
    }

    /**
     * #29: the query scopes exist so that "every past_due subscription" is a query rather than
     * a table scan, which only pays off if the query has an index to land on.
     *
     * The column ORDER is the assertion, not merely the membership. These are the columns
     * Concerns\ManagesSubscriptions::subscriptions() filters on, in the order it filters them —
     * a composite index is only usable left to right, so the same four columns permuted would
     * satisfy a membership check while being unusable to the query it exists for.
     */
    public function test_subscriptions_are_indexed_by_owner_provider_and_status(): void
    {
        $columns = collect(Schema::getIndexes('cashier_subscriptions'))
            ->map(fn (array $index): array => $index['columns'])
            ->values()
            ->all();

        $this->assertContains(
            ['owner_type', 'owner_id', 'provider', 'status'],
            $columns,
            'The scopes have no index to land on. Indexes present: '.json_encode($columns),
        );
    }

    /**
     * Every index this package creates must be nameable on MySQL, whose identifier limit is 64
     * characters.
     *
     * The composite index above is the tight one: Laravel names it from the table and all four
     * columns, giving `cashier_subscriptions_owner_type_owner_id_provider_status_index` — 63
     * characters, one under the limit. That is thin enough to be worth a test rather than a
     * comment, and thin enough that adding a fifth column, or renaming a column, breaks a host
     * app's first migrate rather than anything we would notice here.
     *
     * Known gap this does NOT cover: Blueprint::createIndexName() prepends the connection's table
     * prefix when `prefix_indexes` is set, which is the default in Laravel's shipped
     * config/database.php. A host app with a non-empty DB_PREFIX therefore gets 63 + prefix, and
     * MySQL rejects it. Testbench runs with no prefix, so this test cannot see that; an explicit
     * name in the migration is the fix if it ever bites.
     *
     * Reads names out of the SCHEMA rather than measuring a literal: asserting
     * `strlen('...') <= 64` against a copy of the name would compare two constants and keep
     * passing after any rename. Tests run on SQLite, which has no identifier limit and will
     * never report the failure itself — so the budget must be asserted against what the
     * migration actually created, or it is not asserted at all.
     */
    public function test_every_index_name_clears_mysqls_identifier_limit(): void
    {
        foreach (['cashier_subscriptions', 'cashier_subscription_items', 'cashier_invoices', 'cashier_customers'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) $index['name'];

                $this->assertLessThanOrEqual(64, strlen($name), sprintf(
                    'MySQL rejects identifiers over 64 characters; %s.%s is %d.',
                    $table,
                    $name,
                    strlen($name),
                ));
            }
        }
    }
}
