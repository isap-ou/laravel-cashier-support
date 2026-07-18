<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\IncompletePaymentException;

/**
 * One-off charges and refunds for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait PerformsCharges
{
    use InteractsWithProvider;

    /**
     * Charge the entity for the given amount in minor units (cents).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When the amount is not positive.
     * @throws IncompletePaymentException When the charge needs additional action (e.g. 3DS/SCA)
     *                                    before it can complete — the exception carries the
     *                                    client secret needed to resume it.
     */
    public function charge(int $amount, string $paymentMethod, array $options = []): Payment
    {
        // Without this, a caller's own bug reaches the gateway, comes back as a
        // 4xx, and is delivered as a CashierException — a billing failure the app
        // is invited to catch and swallow. Checkout already guards its amount;
        // this is the same guard, on the same kind of mistake.
        if ($amount <= 0) {
            throw new InvalidArgumentException("A charge amount must be positive in minor units; got [{$amount}].");
        }

        $this->ensureCashierSupports(Capability::Charges);

        $payment = $this->cashierProvider()->charge($this, $amount, $paymentMethod, $options);

        // An incomplete charge (3DS/SCA) must surface as a catchable exception carrying the
        // client secret, not be returned as a silently-stalled "success" the caller mistakes
        // for a completed payment. Mirrors Laravel\Cashier\Concerns\PerformsCharges::charge(),
        // which calls $payment->validate() before returning
        // (vendor/laravel/cashier/src/Concerns/PerformsCharges.php:35), in the same priority
        // order as Laravel\Cashier\Payment::validate() (vendor/laravel/cashier/src/Payment.php:163).
        if ($payment->requiresPaymentMethod()) {
            throw IncompletePaymentException::requiresPaymentMethod($payment->id, $payment->clientSecret);
        }

        if ($payment->requiresAction()) {
            throw IncompletePaymentException::requiresAction($payment->id, $payment->clientSecret);
        }

        if ($payment->requiresConfirmation()) {
            throw IncompletePaymentException::requiresConfirmation($payment->id, $payment->clientSecret);
        }

        return $payment;
    }

    /**
     * Refund a previous payment.
     *
     * @param  array<string, mixed>  $options
     */
    public function refund(string $paymentId, array $options = []): Refund
    {
        $this->ensureCashierSupports(Capability::Refunds);

        return $this->cashierProvider()->refund($this, $paymentId, $options);
    }
}
