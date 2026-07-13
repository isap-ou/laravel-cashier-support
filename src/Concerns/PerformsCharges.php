<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Enums\Capability;

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

        return $this->cashierProvider()->charge($this, $amount, $paymentMethod, $options);
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
