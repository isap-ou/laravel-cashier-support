<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

/**
 * A billable that declares per-price tax rates only — the other half of the
 * tax surface, which must gate exactly like taxRates().
 */
class PriceTaxedUser extends Model
{
    use Billable;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<string, array<int, string>>
     */
    public function priceTaxRates(): array
    {
        return ['price_1' => ['txr_vat_21']];
    }
}
