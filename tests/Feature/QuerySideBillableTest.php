<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class QuerySideBillableTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::extend('fake', fn () => new FakeGateway([Capability::Subscriptions]));
        Cashier::useModels('fake', [
            'subscription' => ConcreteSubscription::class,
            'subscription_item' => ConcreteSubscriptionItem::class,
        ]);
    }

    private function userWithSubscription(SubscriptionStatus $status, array $overrides = []): User
    {
        $user = User::query()->create(['name' => 'Ada']);

        ConcreteSubscription::query()->create(array_merge([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'status' => $status,
        ], $overrides));

        return $user;
    }

    public function test_subscription_and_subscribed_read_the_local_record(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Active);

        $this->assertNotNull($user->subscription('default'));
        $this->assertTrue($user->subscribed('default'));
        $this->assertFalse($user->subscribed('other'));
        $this->assertCount(1, $user->subscriptions()->get());
    }

    public function test_on_trial_and_grace_period(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Trialing, [
            'trial_ends_at' => now()->addDays(7),
        ]);

        $this->assertTrue($user->onTrial('default'));
        $this->assertFalse($user->onGracePeriod('default'));

        $graced = $this->userWithSubscription(SubscriptionStatus::Canceled, [
            'provider_id' => 'sub_2',
            'ends_at' => now()->addDays(3),
        ]);

        $this->assertTrue($graced->onGracePeriod('default'));
        // A canceled subscription within its paid-through grace period still
        // grants access — the customer paid until ends_at.
        $this->assertTrue($graced->subscribed('default'));
    }

    /**
     * The mirror image of the grace period above: there, the customer had paid
     * through ends_at, so access survived cancellation. Here the money never
     * arrived at all, and a paid-through date it never earned must not hand it
     * back — Stripe denies both of these unconditionally.
     */
    #[DataProvider('unrecoverableStatuses')]
    public function test_an_unrecoverable_status_is_denied_access_despite_a_future_ends_at(
        SubscriptionStatus $status,
    ): void {
        $user = $this->userWithSubscription($status, [
            'ends_at' => now()->addDays(3),
        ]);

        $this->assertTrue($user->onGracePeriod('default'));
        $this->assertFalse($user->subscription('default')?->active());
        $this->assertFalse($user->subscribed('default'));
    }

    /**
     * @return array<string, array{SubscriptionStatus}>
     */
    public static function unrecoverableStatuses(): array
    {
        return [
            'dunning exhausted' => [SubscriptionStatus::Unpaid],
            'initial payment never completed' => [SubscriptionStatus::IncompleteExpired],
        ];
    }

    public function test_a_fully_ended_subscription_is_not_subscribed(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Canceled, [
            'ends_at' => now()->subDay(),
        ]);

        $this->assertFalse($user->subscribed('default'));
        $this->assertFalse($user->onGracePeriod('default'));
    }

    public function test_a_stale_trialing_status_with_a_past_trial_end_is_not_on_trial(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Trialing, [
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->assertFalse($user->onTrial('default'));
    }

    public function test_subscriptions_are_scoped_to_the_model_driver(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Active);

        // A record written by another driver must not leak into this view.
        ConcreteSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'other-driver',
            'provider_id' => 'sub_foreign',
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertCount(1, $user->subscriptions()->get());
        $this->assertSame('sub_1', $user->subscription('default')?->getAttribute('provider_id'));
    }

    public function test_subscribed_to_price_checks_the_items(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Active);

        $user->subscription('default')?->items()->create([
            'provider' => 'fake',
            'price' => 'price_monthly',
            'quantity' => 1,
        ]);

        $this->assertTrue($user->subscribedToPrice('price_monthly'));
        $this->assertFalse($user->subscribedToPrice('price_yearly'));
        $this->assertTrue($user->subscribed('default', 'price_monthly'));
        $this->assertFalse($user->subscribed('default', 'price_yearly'));
        $this->assertFalse($user->onTrial('default', 'price_monthly'));
    }

    public function test_an_unregistered_driver_slot_is_a_configuration_error(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        Cashier::invoiceModel('fake');
    }
}
