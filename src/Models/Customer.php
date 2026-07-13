<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Abstract local record of the identity a gateway assigns to a billable entity.
 *
 * Concrete provider packages extend this model and register it with
 * Cashier::useModels($driver, ['customer' => ...]).
 *
 * @property string $provider
 * @property string $provider_id
 * @property string|null $name
 * @property string|null $email
 */
abstract class Customer extends Model
{
    use HasUuids;

    protected $table = 'cashier_customers';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The billable entity this identity belongs to.
     *
     * Polymorphic on purpose: an order webhook resolves its owner from here, and
     * a User and a Team must both be reachable.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
