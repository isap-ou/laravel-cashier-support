<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Isapp\CashierSupport\Facades\Cashier;

/**
 * Abstract local record of a single item within a subscription.
 *
 * @property string $price
 * @property int|null $quantity Null when the gateway has no per-subscription
 *                              quantity, or will not report one back. "Unknown",
 *                              never zero and never one.
 */
abstract class SubscriptionItem extends Model
{
    use HasUuids;

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
     * Resolved per driver via this record's provider column (mirroring
     * Subscription::items()), so the inverse relation hydrates the right
     * concrete class in multi-driver installs.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        $provider = $this->getAttribute('provider');

        return $this->belongsTo(Cashier::subscriptionModel(is_string($provider) ? $provider : null), 'subscription_id');
    }
}
