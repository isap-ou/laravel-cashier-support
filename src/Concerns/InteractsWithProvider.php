<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
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
     *
     * The returned provider is capability-guarded (Cashier::provider() wraps every driver in
     * GuardedProvider), so a concern delegates straight to it — the gate is not the concern's to
     * remember. That is why this trait no longer carries an ensureCashierSupports() helper.
     */
    protected function cashierProvider(): GatewayProvider
    {
        return Cashier::provider($this->cashierDriver());
    }
}
