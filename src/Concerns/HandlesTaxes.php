<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;

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
 * provider that does not will throw rather than discard them: Gateway\GuardedProvider
 * gates a subscription create/swap on Capability::Taxes when the owner declares any.
 *
 * @phpstan-require-extends Model
 */
trait HandlesTaxes
{
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
}
