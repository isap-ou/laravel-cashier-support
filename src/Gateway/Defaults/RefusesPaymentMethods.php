<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\PaymentMethodOperations, refused.
 *
 * Three capabilities across four methods, and they come apart in practice: Revolut can list
 * and delete a payment method but cannot add one, because the card is captured on the
 * gateway's own page and never passes through the app.
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 *
 * @internal Composed into Gateway\BaseGateway, which a driver extends — never used directly (two traits defining one method is a fatal collision; see BaseGateway's docblock). Not public surface: outside the backward-compatibility promise in README.
 */
trait RefusesPaymentMethods
{
    /**
     * @return array<int, PaymentMethod>
     */
    public function paymentMethods(Model $billable): array
    {
        throw UnsupportedOperationException::forCapability(Capability::PaymentMethodsList);
    }

    public function defaultPaymentMethod(Model $billable): ?PaymentMethod
    {
        throw UnsupportedOperationException::forCapability(Capability::PaymentMethodsList);
    }

    public function addPaymentMethod(Model $billable, string $paymentMethod): PaymentMethod
    {
        throw UnsupportedOperationException::forCapability(Capability::PaymentMethodsAdd);
    }

    public function deletePaymentMethod(Model $billable, string $paymentMethodId): void
    {
        throw UnsupportedOperationException::forCapability(Capability::PaymentMethodsDelete);
    }
}
