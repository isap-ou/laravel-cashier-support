<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Payment method management operations at the gateway provider.
 */
interface PaymentMethodOperations
{
    /**
     * List the stored payment methods for the billable entity.
     *
     * @return array<int, PaymentMethod>
     *
     * @throws UnsupportedOperationException When the provider cannot list payment methods.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     */
    public function paymentMethods(Model $billable): array;

    /**
     * The default payment method for the billable entity, if any.
     *
     * @throws UnsupportedOperationException When the provider cannot list payment methods.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     */
    public function defaultPaymentMethod(Model $billable): ?PaymentMethod;

    /**
     * Add a payment method to the billable entity.
     *
     * @param  string  $paymentMethod  The payment method identifier.
     *
     * @throws UnsupportedOperationException When the provider cannot add payment methods.
     * @throws CashierException When the gateway call fails.
     */
    public function addPaymentMethod(Model $billable, string $paymentMethod): PaymentMethod;

    /**
     * Delete a stored payment method.
     *
     * @throws UnsupportedOperationException When the provider cannot delete payment methods.
     * @throws CashierException When the gateway call fails.
     */
    public function deletePaymentMethod(Model $billable, string $paymentMethodId): void;
}
