<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\Payment;

/**
 * Dispatched when a payment has succeeded.
 */
class PaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly Payment $payment,
    ) {}
}
