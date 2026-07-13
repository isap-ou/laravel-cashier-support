<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Tax configuration for a billable model.
 *
 * These methods are extension points: an application overrides them on its
 * billable model to declare the tax rate identifiers that apply. By default no
 * tax rates are applied. This concern performs no provider calls.
 *
 * The rate-identifier shape is deliberately Stripe-family: a Stripe tax-rate id
 * means nothing to a gateway that models tax as a percentage, and nothing at all
 * to a Merchant-of-Record gateway that owns tax end to end. Rates declared here
 * are therefore read only by a provider that declares Capability::Taxes — and a
 * provider that does not will now throw rather than discard them (see
 * InteractsWithProvider::ensureTaxRatesSupported()).
 *
 * @phpstan-require-extends Model
 */
trait HandlesTaxes
{
    use InteractsWithProvider;

    /**
     * The tax rate identifiers that apply to this entity's subscriptions and charges.
     *
     * @return array<int, string>
     */
    public function taxRates(): array
    {
        return [];
    }

    /**
     * The tax rate identifiers that apply to specific prices.
     *
     * @return array<string, array<int, string>>
     */
    public function priceTaxRates(): array
    {
        return [];
    }

    /**
     * Ensure tax rates declared on this model can actually be honoured.
     *
     * A provider that cannot apply them would otherwise discard them in
     * silence — the one place in this package where "unsupported" meant
     * "ignored" rather than "throw".
     *
     * Called where the rates are *consumed*, never on taxRates() itself: the
     * app overrides that method, and we are the ones who call it. Following
     * Stripe Cashier, the consumption points are subscription creation and
     * swap — a one-off charge and a checkout session never read them.
     *
     * @throws UnsupportedOperationException When rates are declared and the provider has no tax support.
     */
    protected function ensureTaxRatesSupported(): void
    {
        if ($this->taxRates() === [] && $this->priceTaxRates() === []) {
            return;
        }

        $this->ensureCashierSupports(Capability::Taxes);
    }
}
