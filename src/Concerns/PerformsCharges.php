<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
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
     */
    public function charge(int $amount, string $paymentMethod, array $options = []): Payment
    {
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
