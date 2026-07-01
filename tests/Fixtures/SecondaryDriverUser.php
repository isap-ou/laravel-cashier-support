<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

/**
 * A billable model that bills through a non-default gateway driver.
 */
class SecondaryDriverUser extends Model
{
    use Billable;

    protected $guarded = [];

    public function cashierDriver(): ?string
    {
        return 'secondary';
    }
}
