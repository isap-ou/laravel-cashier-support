<?php

declare(strict_types=1);

namespace Isapp\CashierSupport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Manager;
use Illuminate\Support\Traits\Macroable;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
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
     * Resolve a gateway provider driver.
     *
     * @throws InvalidConfigurationException When the resolved driver is not a gateway provider.
     */
    public function provider(?string $driver = null): GatewayProvider
    {
        $provider = $this->driver($driver);

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
     * Resolve a model slot: driver registry first, then the published
     * cashier-support.models.* config.
     *
     * @param  class-string<Model>  $abstract  The abstract model the slot must extend.
     * @return class-string<Model>
     *
     * @throws InvalidConfigurationException When nothing concrete is registered.
     */
    protected function model(string $slot, string $abstract, ?string $driver = null): string
    {
        $driver ??= $this->getDefaultDriver();

        $class = $this->models[$driver][$slot]
            ?? $this->config->get("cashier-support.models.{$slot}");

        if (! is_string($class) || ! is_subclass_of($class, $abstract)) {
            throw InvalidConfigurationException::missingKey("cashier-support.models.{$slot} (driver [{$driver}])");
        }

        return $class;
    }
}
