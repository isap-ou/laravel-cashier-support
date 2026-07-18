<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;

/**
 * One-off charge and refund operations.
 */
interface ChargeOperations
{
    /**
     * Charge the billable entity for the given amount.
     *
     * The returned Payment may still be incomplete — e.g. `requires_action` for 3DS/SCA. A
     * driver returns that state as data; `Concerns\PerformsCharges::charge()` is what turns it
     * into a catchable `IncompletePaymentException`.
     *
     * @param  int  $amount  Amount in minor units (cents).
     * @param  string  $paymentMethod  The payment method identifier.
     * @param  array<string, mixed>  $options
     *
     * @throws PaymentFailedException When the charge is declined.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     * @throws InvalidArgumentException When the amount is not positive.
     */
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment;

    /**
     * Refund a previous payment.
     *
     * @param  string  $paymentId  The identifier of the payment to refund.
     * @param  array<string, mixed>  $options
     *
     * @throws PaymentFailedException When the provider rejects the refund.
     * @throws CashierException When the gateway call fails.
     */
    public function refund(Model $billable, string $paymentId, array $options = []): Refund;
}
