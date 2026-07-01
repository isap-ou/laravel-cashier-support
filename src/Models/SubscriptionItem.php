<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abstract local record of a single item within a subscription.
 *
 * @property string $price
 * @property int $quantity
 */
abstract class SubscriptionItem extends Model
{
    protected $table = 'cashier_subscription_items';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * The subscription this item belongs to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        /** @var class-string<Subscription> $model */
        $model = config('cashier-support.models.subscription') ?? Subscription::class;

        return $this->belongsTo($model);
    }
}
