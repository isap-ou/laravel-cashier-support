<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\ChargeOperations, refused.
 *
 * Composed into Gateway\BaseGateway — not meant to be used directly by a driver. A driver
 * that mixed this in alongside a trait of its own implementing charge() would hit the fatal
 * trait collision that made BaseGateway a class in the first place; inherited from the base
 * it is simply overridden. See BaseGateway.
 *
 * @internal Composed into Gateway\BaseGateway, which a driver extends — never used directly (two traits defining one method is a fatal collision; see BaseGateway's docblock). Not public surface: outside the backward-compatibility promise in README.
 */
trait RefusesCharges
{
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment
    {
        throw UnsupportedOperationException::forCapability(Capability::Charges);
    }

    public function refund(Model $billable, string $paymentId, array $options = []): Refund
    {
        throw UnsupportedOperationException::forCapability(Capability::Refunds);
    }
}
