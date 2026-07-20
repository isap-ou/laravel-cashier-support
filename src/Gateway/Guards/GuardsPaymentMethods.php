<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Capability gating for the PaymentMethodOperations surface, composed into GuardedProvider.
 *
 * @internal Composed into Gateway\GuardedProvider, which is what Cashier::provider() returns. An app reaches this through the facade, never by name. Not public surface: outside the backward-compatibility promise in README.
 */
trait GuardsPaymentMethods
{
    /**
     * {@inheritDoc}
     */
    public function paymentMethods(Model $billable): array
    {
        $this->ensure(Capability::PaymentMethodsList);

        return $this->inner()->paymentMethods($billable);
    }

    /**
     * {@inheritDoc}
     */
    public function defaultPaymentMethod(Model $billable): ?PaymentMethod
    {
        $this->ensure(Capability::PaymentMethodsList);

        return $this->inner()->defaultPaymentMethod($billable);
    }

    /**
     * {@inheritDoc}
     */
    public function addPaymentMethod(Model $billable, string $paymentMethod): PaymentMethod
    {
        $this->ensure(Capability::PaymentMethodsAdd);

        return $this->inner()->addPaymentMethod($billable, $paymentMethod);
    }

    /**
     * {@inheritDoc}
     */
    public function deletePaymentMethod(Model $billable, string $paymentMethodId): void
    {
        $this->ensure(Capability::PaymentMethodsDelete);

        $this->inner()->deletePaymentMethod($billable, $paymentMethodId);
    }
}
