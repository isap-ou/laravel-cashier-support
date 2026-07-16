<?php

declare(strict_types=1);

namespace Isapp\CashierSupport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Manager;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Models\Customer as CustomerModel;
use Isapp\CashierSupport\Models\Invoice as InvoiceModel;
use Isapp\CashierSupport\Models\Subscription as SubscriptionModel;
use Isapp\CashierSupport\Models\SubscriptionItem as SubscriptionItemModel;

/**
 * Driver manager for gateway providers.
 *
 * Concrete provider packages register a driver via extend():
 *
 *     Cashier::extend('revolut', fn ($app) => $app->make(RevolutGateway::class));
 *
 * The active driver is chosen by config('cashier-support.default').
 *
 * The manager is macroable, so applications can attach helpers via
 * Cashier::macro(); unknown non-macro calls keep the Manager behaviour of
 * forwarding to the default driver.
 */
class CashierManager extends Manager
{
    use Macroable {
        Macroable::__call as macroCall;
    }

    /**
     * Handle macros first, then fall back to Manager's driver forwarding.
     *
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return parent::__call($method, $parameters);
    }

    /**
     * The default driver name from configuration.
     *
     * @throws InvalidConfigurationException When no default driver is configured.
     */
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('cashier-support.default');

        if (! is_string($driver) || $driver === '') {
            throw InvalidConfigurationException::noProviderBound();
        }

        return $driver;
    }

    /**
     * The names of every driver a provider package has registered.
     *
     * Manager::getDrivers() cannot answer this: it returns $this->drivers, the cache of
     * already-RESOLVED instances, which is empty until something resolves one. The names
     * passed to extend() live in Manager::$customCreators, and this is the only way to
     * read them without resolving every driver as a side effect of listing them.
     *
     * @return array<int, string>
     */
    public function registeredDrivers(): array
    {
        return array_keys($this->customCreators);
    }

    /**
     * Resolve a gateway provider driver.
     *
     * @throws InvalidConfigurationException When the driver is unknown or not a gateway provider.
     */
    public function provider(?string $driver = null): GatewayProvider
    {
        try {
            $provider = $this->driver($driver);
        } catch (InvalidArgumentException $exception) {
            // Keep unknown drivers inside the CashierException hierarchy.
            throw new InvalidConfigurationException($exception->getMessage());
        }

        if (! $provider instanceof GatewayProvider) {
            throw InvalidConfigurationException::noProviderBound();
        }

        return $provider;
    }

    /**
     * Whether the given (or default) provider supports a capability.
     */
    public function supports(Capability $capability, ?string $driver = null): bool
    {
        return $this->provider($driver)->supports($capability);
    }

    /**
     * Ensure the given (or default) provider supports a capability.
     *
     * @throws UnsupportedOperationException When the capability is not supported.
     */
    public function ensureSupports(Capability $capability, ?string $driver = null): void
    {
        if (! $this->supports($capability, $driver)) {
            throw UnsupportedOperationException::forCapability($capability);
        }
    }

    /**
     * Per-driver local model classes: [driver => [slot => class]].
     *
     * @var array<string, array<string, class-string<Model>>>
     */
    protected array $models = [];

    /**
     * Register the local model classes a driver's records use.
     *
     * Called by a driver's service provider:
     *
     *     Cashier::useModels('revolut', [
     *         'subscription' => RevolutSubscription::class,
     *         'subscription_item' => RevolutSubscriptionItem::class,
     *         'invoice' => RevolutInvoice::class,
     *     ]);
     *
     * @param  array<string, class-string<Model>>  $models
     */
    public function useModels(string $driver, array $models): void
    {
        $this->models[$driver] = array_merge($this->models[$driver] ?? [], $models);
    }

    /**
     * Whether a driver has registered a model for a slot.
     *
     * Lets a read path answer "no record" instead of exploding: a driver that
     * stores no customers is a legitimate driver, and asking whether a billable
     * is a customer must not require it to register a model it never writes.
     *
     * "Nobody named a model" is the only question this answers. It deliberately
     * does NOT validate what was named, so a slot the app misconfigured returns
     * true here and throws in model() — which is the intent: a bad override
     * must be loud, and answering false would hand the caller a silent "no
     * record" for what is really a typo. The guard covers a driver that
     * registered nothing, not an app that registered nonsense.
     *
     * The registry is checked first only because this returns a bool: unlike
     * model(), where config outranks the registry, an OR of the two sources
     * gives the same answer in either order.
     */
    public function hasModel(string $slot, ?string $driver = null): bool
    {
        $driver ??= $this->getDefaultDriver();

        if (isset($this->models[$driver][$slot])) {
            return true;
        }

        return $driver === $this->config->get('cashier-support.default')
            && is_string($this->config->get("cashier-support.models.{$slot}"));
    }

    /**
     * The customer model class for a driver.
     *
     * @return class-string<CustomerModel>
     */
    public function customerModel(?string $driver = null): string
    {
        /** @var class-string<CustomerModel> */
        return $this->model('customer', CustomerModel::class, $driver);
    }

    /**
     * The subscription model class for a driver.
     *
     * @return class-string<SubscriptionModel>
     */
    public function subscriptionModel(?string $driver = null): string
    {
        /** @var class-string<SubscriptionModel> */
        return $this->model('subscription', SubscriptionModel::class, $driver);
    }

    /**
     * The subscription item model class for a driver.
     *
     * @return class-string<SubscriptionItemModel>
     */
    public function subscriptionItemModel(?string $driver = null): string
    {
        /** @var class-string<SubscriptionItemModel> */
        return $this->model('subscription_item', SubscriptionItemModel::class, $driver);
    }

    /**
     * The invoice model class for a driver.
     *
     * @return class-string<InvoiceModel>
     */
    public function invoiceModel(?string $driver = null): string
    {
        /** @var class-string<InvoiceModel> */
        return $this->model('invoice', InvoiceModel::class, $driver);
    }

    /**
     * Resolve a model slot. The published cashier-support.models.* config is
     * the app's override and OUTRANKS the driver's registry, for the DEFAULT
     * driver only — it must never bleed one driver's models into another.
     *
     * The order is the whole point. Consulting the registry first made the
     * config reachable only for a slot whose driver had registered nothing —
     * so for every slot a driver DID register, publishing the config did
     * nothing at all. cashier-revolut registers all four, so in that install
     * it did nothing whatsoever; a driver that leaves a slot empty is
     * legitimate (see hasModel()), and there the config worked. It applied
     * precisely where the app was least likely to want it.
     *
     * @param  class-string<Model>  $abstract  The abstract model the slot must extend.
     * @return class-string<Model>
     *
     * @throws InvalidConfigurationException When neither the config nor the driver names a usable class.
     */
    protected function model(string $slot, string $abstract, ?string $driver = null): string
    {
        $driver ??= $this->getDefaultDriver();

        // The config is the app's override for the driver it named as default,
        // and only that one — another driver's models are its own business.
        $readsConfig = $driver === $this->config->get('cashier-support.default');

        // The class the app named, if it named one. Held as the value rather
        // than a boolean flag: which source $class came from has to survive to
        // the guard below, and a ?string carries that AND its own non-nullness.
        $configuredClass = null;

        if ($readsConfig) {
            $configured = $this->config->get("cashier-support.models.{$slot}");

            if (is_string($configured)) {
                $configuredClass = $configured;
            }
        }

        // Only when the app named nothing. A config value that IS named but
        // unusable falls through to the guard below rather than to the driver:
        // an override that loses silently is the same defect as one that is
        // never read.
        $class = $configuredClass ?? $this->models[$driver][$slot] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, $abstract)) {
            // Two different mistakes reach this line, and blaming the driver
            // for the app's is how an afternoon gets lost. Which one it was is
            // remembered rather than re-derived: asking "does $class equal the
            // config value?" would misattribute a driver that registered the
            // very class the config happens to name.
            if ($configuredClass !== null) {
                // A hand-typed class name is the likeliest thing to be wrong
                // here, and is_subclass_of() cannot tell "misspelled" from
                // "wrong parent" — it returns false for both. Saying "does not
                // extend" about a class that does not exist sends the reader to
                // inspect an inheritance chain that was never the problem.
                throw new InvalidConfigurationException(
                    class_exists($configuredClass)
                        ? "The [cashier-support.models.{$slot}] config names [{$configuredClass}], which does not extend [{$abstract}]."
                        : "The [cashier-support.models.{$slot}] config names [{$configuredClass}], which does not exist.",
                );
            }

            // Only offer the config to a caller the config can actually help.
            // Advice that cannot work costs the reader the time to try it.
            $orConfig = $readsConfig
                ? ", or set [cashier-support.models.{$slot}] in the published config"
                : '';

            throw new InvalidConfigurationException(
                "The [{$driver}] driver has not registered a [{$slot}] model — call Cashier::useModels('{$driver}', [...]) in its service provider{$orConfig}.",
            );
        }

        return $class;
    }
}
