<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Tax configuration for a billable model.
 *
 * These methods are extension points: an application overrides them on its
 * billable model to declare the tax rate identifiers that apply. By default
 * no tax rates are applied. This concern performs no provider calls.
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
