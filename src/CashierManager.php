<?php

declare(strict_types=1);

namespace Isapp\CashierSupport;

use Illuminate\Support\Manager;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Driver manager for gateway providers.
 *
 * Concrete provider packages register a driver via extend():
 *
 *     Cashier::extend('revolut', fn ($app) => $app->make(RevolutGateway::class));
 *
 * The active driver is chosen by config('cashier-support.default').
 */
class CashierManager extends Manager
{
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
}
