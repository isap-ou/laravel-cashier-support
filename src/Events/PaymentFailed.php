<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\Payment;

/**
 * Dispatched when a payment has failed.
 */
class PaymentFailed
{
    use SerializesModels;

    public function __construct(
        public readonly Model $billable,
        public readonly Payment $payment,
    ) {}
}
