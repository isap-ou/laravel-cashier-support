<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Resolves the gateway provider for a billable model.
 *
 * By default the model uses the configured default driver. Override
 * cashierDriver() on the model to bill it through a specific provider,
 * so different models can use different gateways.
 *
 * @phpstan-require-extends Model
 */
trait InteractsWithProvider
{
    /**
     * The gateway driver this model bills through (null = configured default).
     */
    public function cashierDriver(): ?string
    {
        return null;
    }

    /**
     * Resolve the gateway provider for this model.
     */
    protected function cashierProvider(): GatewayProvider
    {
        return Cashier::provider($this->cashierDriver());
    }

    /**
     * Ensure this model's provider supports the given capability.
     */
    protected function ensureCashierSupports(Capability $capability): void
    {
        Cashier::ensureSupports($capability, $this->cashierDriver());
    }
}
