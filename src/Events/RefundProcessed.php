<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Refund;

/**
 * Dispatched when a refund has been processed.
 */
class RefundProcessed
{
    public function __construct(
        public readonly Model $billable,
        public readonly Refund $refund,
    ) {}
}
