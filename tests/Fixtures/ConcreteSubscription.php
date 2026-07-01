<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Isapp\CashierSupport\Models\Subscription;

class ConcreteSubscription extends Subscription
{
    public function items(): HasMany
    {
        return $this->hasMany(ConcreteSubscriptionItem::class, 'subscription_id');
    }
}
