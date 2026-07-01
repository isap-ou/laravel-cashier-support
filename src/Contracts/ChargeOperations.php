<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;

/**
 * One-off charge and refund operations.
 */
interface ChargeOperations
{
    /**
     * Charge the billable entity for the given amount.
     *
     * @param  int  $amount  Amount in minor units (cents).
     * @param  string  $paymentMethod  The payment method identifier.
     * @param  array<string, mixed>  $options
     *
     * @throws PaymentFailedException When the charge is declined.
     */
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment;

    /**
     * Refund a previous payment.
     *
     * @param  string  $paymentId  The identifier of the payment to refund.
     * @param  array<string, mixed>  $options
     */
    public function refund(Model $billable, string $paymentId, array $options = []): Refund;
}
