<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Capability gating for the ChargeOperations surface, composed into GuardedProvider.
 */
trait GuardsCharges
{
    /**
     * {@inheritDoc}
     */
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment
    {
        $this->ensure(Capability::Charges);

        return $this->inner()->charge($billable, $amount, $paymentMethod, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function refund(Model $billable, string $paymentId, array $options = []): Refund
    {
        $this->ensure(Capability::Refunds);

        return $this->inner()->refund($billable, $paymentId, $options);
    }
}
