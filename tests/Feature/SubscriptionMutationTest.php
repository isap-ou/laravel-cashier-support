<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\TaxedUser;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * #39: the subscription lifecycle mutations live on Models\Subscription now
 * (`$user->subscription('default')->cancel()`), matching Stripe/Paddle, not on Billable.
 *
 * The model methods carry NO capability gate: they delegate through Cashier::provider(), which is
 * capability-guarded, so the check is not the model's to make and an override cannot drop it. This
 * class proves the model API delegates and refuses correctly; GuardedProviderTest proves the guard
 * that does the refusing.
 */
class SubscriptionMutationTest extends TestCase
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

    /**
     * A persisted subscription and its persisted owner — refresh() needs the row, and the model's
     * mutators resolve the owner to hand the gateway.
     *
     * @param  class-string<Model>  $ownerType
     */
    private function subscription(string $ownerType = User::class): ConcreteSubscription
    {
        /** @var Model $owner */
        $owner = $ownerType::create(['name' => 'Ada']);

        return ConcreteSubscription::create([
            'owner_type' => $ownerType,
            'owner_id' => $owner->getKey(),
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
        ]);
    }

    public function test_cancel_delegates_through_the_gateway_and_returns_the_refreshed_model(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions]);
        $subscription = $this->subscription();

        $result = $subscription->cancel();

        $gateway->assertSubscriptionCanceled();
        $this->assertInstanceOf(ConcreteSubscription::class, $result);
        $this->assertTrue($result->is($subscription), 'cancel() returns $this refreshed, not the gateway DTO.');
    }

    public function test_cancel_is_refused_when_the_gateway_does_not_support_subscriptions(): void
    {
        $this->driverSupporting([]);
        $subscription = $this->subscription();

        $this->expectException(UnsupportedOperationException::class);
        $subscription->cancel();
    }

    public function test_cancel_now_needs_its_own_capability(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionCancelNow]);

        $this->subscription()->cancelNow();

        $gateway->assertSubscriptionCanceled();
    }

    public function test_cancel_now_is_refused_without_the_cancel_now_capability(): void
    {
        // Subscriptions alone is not enough — cancelNow is its own gate.
        $this->driverSupporting([Capability::Subscriptions]);
        $subscription = $this->subscription();

        $this->expectException(UnsupportedOperationException::class);
        $subscription->cancelNow();
    }

    public function test_resume_returns_the_model_when_supported(): void
    {
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionResume]);
        $subscription = $this->subscription();

        $result = $subscription->resume();

        $this->assertTrue($result->is($subscription));
    }

    public function test_resume_is_refused_without_the_resume_capability(): void
    {
        $this->driverSupporting([Capability::Subscriptions]);
        $subscription = $this->subscription();

        $this->expectException(UnsupportedOperationException::class);
        $subscription->resume();
    }

    public function test_pause_passes_its_resume_date_to_the_gateway(): void
    {
        // Pause is single-intent since #72; $until (Stripe's pause_collection.resumes_at) is the
        // one thing that must reach the gateway rather than being dropped at the Billable gate.
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionPauseImmediate]);
        $until = CarbonImmutable::parse('2026-09-01T00:00:00Z');

        $this->subscription()->pause($until);

        $this->assertSame($until, $gateway->lastPauseUntil);
    }

    public function test_pause_is_refused_without_the_pause_capability(): void
    {
        $this->driverSupporting([Capability::Subscriptions]);
        $subscription = $this->subscription();

        $this->expectException(UnsupportedOperationException::class);
        $subscription->pause();
    }

    public function test_swap_defaults_to_immediate(): void
    {
        // Stripe and Paddle both swap immediately, so that is the default; a defer-only gateway
        // must be told the caller wanted "now" and refuse it.
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionSwapAtPeriodEnd]);
        $subscription = $this->subscription();

        $this->expectException(UnsupportedOperationException::class);
        $subscription->swap('price_2');
    }

    public function test_swap_works_for_the_timing_the_gateway_supports(): void
    {
        $gateway = $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionSwapAtPeriodEnd]);
        $subscription = $this->subscription();

        $result = $subscription->swap('price_2', SwapTiming::AtPeriodEnd);

        $this->assertTrue($result->is($subscription));
        $this->assertSame('price_2', $gateway->lastSwapPrices, 'The prices must reach the gateway.');
        $this->assertSame(SwapTiming::AtPeriodEnd, $gateway->lastSwapTiming, 'The timing must reach the gateway.');
    }

    public function test_swap_refuses_when_the_owner_declares_tax_rates_the_gateway_cannot_apply(): void
    {
        // Migrated from CapabilityGatingTest: swap re-applies the owner's tax rates, a consumption
        // point like creation, so a gateway without Taxes must refuse — now gated for the model
        // path by the guard reading the owner's declared rates.
        $this->driverSupporting([Capability::Subscriptions, Capability::SubscriptionSwapImmediate]);
        $subscription = $this->subscription(TaxedUser::class);

        $this->expectException(UnsupportedOperationException::class);
        $subscription->swap('price_2');
    }
}
