<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

/**
 * A second billable type on the same driver.
 *
 * The whole point of the customer side-table: a flat column on `users` could
 * never serve this, and a reverse lookup by customer id could only ever find
 * one billable class.
 */
class Team extends Model
{
    use Billable;

    protected $guarded = [];
}
