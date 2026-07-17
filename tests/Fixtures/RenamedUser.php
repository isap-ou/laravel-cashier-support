<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

/**
 * A billable that does not keep its name in `name`.
 *
 * The reason the hooks exist, made concrete: before them the only way a driver could learn a
 * name was to read the app's model and assume an attribute, so this model was unserviceable
 * without the app hand-passing its name on every single call. It overrides the seam and
 * nothing else — no driver, no contract and no gateway learns that `full_name` exists.
 */
class RenamedUser extends Model
{
    use Billable;

    protected $table = 'users';

    protected $guarded = [];

    public function cashierName(): ?string
    {
        return is_string($this->full_name) ? $this->full_name : null;
    }
}
