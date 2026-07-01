<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Isapp\CashierSupport\Models\SubscriptionItem;

class ConcreteSubscriptionItem extends SubscriptionItem
{
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ConcreteSubscription::class, 'subscription_id');
    }
}
