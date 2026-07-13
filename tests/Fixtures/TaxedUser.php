<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

/**
 * A billable that declares tax rates — the extension point an app overrides.
 */
class TaxedUser extends Model
{
    use Billable;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return array<int, string>
     */
    public function taxRates(): array
    {
        return ['txr_vat_21'];
    }
}
