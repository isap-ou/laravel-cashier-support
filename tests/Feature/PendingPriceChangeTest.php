<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\DTO\Subscription as SubscriptionData;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\SubscriptionPriceChangeScheduled;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * A change that is scheduled, not yet applied, needs somewhere to live.
 *
 * A gateway that defers a plan change to the end of the billing cycle leaves the
 * subscription billed on the OLD price — which the record must keep naming, or
 * it would lie about what the customer is paying. The requested price then has
 * nowhere to go: it is the single most important output of the operation, and it
 * existed in no column, no DTO field and no event, so a successful swap was
 * indistinguishable from no swap at all. "You'll move to Pro on 1 Aug" — the
 * canonical UI for a deferred change — could not be rendered.
 */
class PendingPriceChangeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscription(array $attributes = []): ConcreteSubscription
    {
        $user = User::query()->create(['name' => 'Ada']);

        /** @var ConcreteSubscription $subscription */
        $subscription = ConcreteSubscription::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'status' => 'active',
            ...$attributes,
        ]);

        return $subscription;
    }

    public function test_a_subscription_with_no_scheduled_change_says_so(): void
    {
        $subscription = $this->subscription();

        $this->assertFalse($subscription->hasPendingPriceChange());
        $this->assertNull($subscription->pendingPrice());
        $this->assertNull($subscription->pendingPriceStartsAt());
    }

    public function test_a_scheduled_change_names_the_price_and_the_date_it_lands(): void
    {
        $startsAt = CarbonImmutable::parse('2099-08-01T00:00:00Z');

        $subscription = $this->subscription([
            'next_price' => 'price_pro',
            'next_price_starts_at' => $startsAt,
        ]);

        $this->assertTrue($subscription->hasPendingPriceChange());
        $this->assertSame('price_pro', $subscription->pendingPrice());
        $this->assertTrue($startsAt->equalTo($subscription->pendingPriceStartsAt()));
    }

    public function test_a_pending_price_with_no_date_is_still_a_pending_price(): void
    {
        // A gateway may schedule the change without saying when it lands. That
        // is "unknown date", not "no change" — dropping the change on the floor
        // because the date is missing is how the requested plan got lost in the
        // first place.
        $subscription = $this->subscription(['next_price' => 'price_pro']);

        $this->assertTrue($subscription->hasPendingPriceChange());
        $this->assertNull($subscription->pendingPriceStartsAt());
    }

    public function test_the_scheduling_event_carries_the_subscription_it_describes(): void
    {
        Event::fake([SubscriptionPriceChangeScheduled::class]);

        $user = User::query()->create(['name' => 'Ada']);
        $subscription = new SubscriptionData(
            id: 'sub_1',
            type: 'default',
            status: SubscriptionStatus::Active,
            pendingPrice: 'price_pro',
            pendingPriceStartsAt: CarbonImmutable::parse('2099-08-01T00:00:00Z'),
        );

        event(new SubscriptionPriceChangeScheduled($user, $subscription));

        Event::assertDispatched(
            SubscriptionPriceChangeScheduled::class,
            fn (SubscriptionPriceChangeScheduled $event): bool => $event->subscription->pendingPrice === 'price_pro'
                && $event->subscription->pendingPriceStartsAt?->toDateString() === '2099-08-01',
        );
    }
}
