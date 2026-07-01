<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Payment;

/**
 * Dispatched when a payment has failed.
 */
class PaymentFailed
{
    public function __construct(
        public readonly Model $billable,
        public readonly Payment $payment,
    ) {}
}
