<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

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
            'name' => 'default',
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
    }

    public function test_subscribed_to_price_checks_the_items(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::Active);

        $user->subscription('default')?->items()->create([
            'price' => 'price_monthly',
            'quantity' => 1,
        ]);

        $this->assertTrue($user->subscribedToPrice('price_monthly'));
        $this->assertFalse($user->subscribedToPrice('price_yearly'));
    }
}
