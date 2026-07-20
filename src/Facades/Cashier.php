<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Facades;

use Illuminate\Support\Facades\Facade;
use Isapp\CashierSupport\CashierManager;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Testing\FakeCustomer;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Testing\FakeInvoice;
use Isapp\CashierSupport\Testing\FakeSubscription;
use Isapp\CashierSupport\Testing\FakeSubscriptionItem;

/**
 * @method static \Isapp\CashierSupport\Contracts\GatewayProvider provider(?string $driver = null)
 * @method static ?\Isapp\CashierSupport\Contracts\RegistersWebhooks webhookRegistrar(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static array<int, string> registeredDrivers()
 * @method static bool supports(\Isapp\CashierSupport\Enums\Capability $capability, ?string $driver = null)
 * @method static void ensureSupports(\Isapp\CashierSupport\Enums\Capability $capability, ?string $driver = null)
 * @method static string formatAmount(int $amount, \Money\Currency|string|null $currency = null, ?string $locale = null, array<string, mixed> $options = [])
 * @method static void formatCurrencyUsing(callable $callback)
 * @method static void keepPastDueSubscriptionsActive()
 * @method static void keepIncompleteSubscriptionsActive()
 * @method static bool deactivatesPastDue()
 * @method static bool deactivatesIncomplete()
 * @method static mixed driver(?string $driver = null)
 * @method static \Isapp\CashierSupport\CashierManager extend(string $driver, \Closure $callback)
 * @method static void macro(string $name, callable|object $macro)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static void useModels(string $driver, array<string, class-string<\Illuminate\Database\Eloquent\Model>> $models)
 * @method static class-string<\Isapp\CashierSupport\Models\Subscription> subscriptionModel(?string $driver = null)
 * @method static class-string<\Isapp\CashierSupport\Models\SubscriptionItem> subscriptionItemModel(?string $driver = null)
 * @method static class-string<\Isapp\CashierSupport\Models\Invoice> invoiceModel(?string $driver = null)
 *
 * @see CashierManager
 */
class Cashier extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CashierManager::class;
    }

    /**
     * Swap in an in-memory FakeGateway as the active driver and return it, so an app can test
     * its billing code with no real driver installed and then assert against the fake.
     *
     * Mirrors Laravel's own Event::fake()/Bus::fake() shape. With no argument the fake supports
     * every capability — the friendly default for "just fake billing"; pass an explicit list to
     * constrain what it answers to, exactly as the FakeGateway constructor does.
     *
     * @param  array<int, Capability>  $capabilities
     */
    public static function fake(array $capabilities = []): FakeGateway
    {
        $fake = new FakeGateway($capabilities === [] ? Capability::cases() : $capabilities);

        /** @var CashierManager $manager */
        $manager = static::getFacadeRoot();
        $manager->extend('fake', fn () => $fake);

        // Bind concrete models for the fake, or half the Billable surface throws.
        // Models\* are abstract so each driver names its own, and nothing named any for
        // [fake] — so $user->charge() worked while $user->subscribed(), ->subscription()
        // and ->subscriptions() raised InvalidConfigurationException. That contradicted the
        // promise directly above ("test its billing code with no real driver installed"),
        // and its remedy could not be followed: the fake has no service provider to call
        // useModels() from, and with no driver installed there was no concrete class to name.
        //
        // This also repairs a regression rather than only a gap: bindings are per-driver, so
        // calling fake() inside a suite that DID have a real driver used to take the working
        // model bindings away with it.
        $manager->useModels('fake', [
            'subscription' => FakeSubscription::class,
            'subscription_item' => FakeSubscriptionItem::class,
            'customer' => FakeCustomer::class,
            'invoice' => FakeInvoice::class,
        ]);

        config()->set('cashier-support.default', 'fake');

        // Drop any driver already resolved this request — including a previous fake() — so the
        // one just registered is what the next provider() call hands back. Without this the
        // Manager's instance cache would keep serving the earlier gateway.
        $manager->forgetDrivers();

        return $fake;
    }
}
