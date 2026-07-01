<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Payment method management for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait ManagesPaymentMethods
{
    use InteractsWithProvider;

    /**
     * List the stored payment methods for the entity.
     *
     * @return array<int, PaymentMethod>
     */
    public function paymentMethods(): array
    {
        $this->ensureCashierSupports(Capability::PaymentMethodsList);

        return $this->cashierProvider()->paymentMethods($this);
    }

    /**
     * The default payment method for the entity, if any.
     */
    public function defaultPaymentMethod(): ?PaymentMethod
    {
        $this->ensureCashierSupports(Capability::PaymentMethodsList);

        return $this->cashierProvider()->defaultPaymentMethod($this);
    }

    /**
     * Add a payment method to the entity.
     */
    public function addPaymentMethod(string $paymentMethod): PaymentMethod
    {
        $this->ensureCashierSupports(Capability::PaymentMethodsAdd);

        return $this->cashierProvider()->addPaymentMethod($this, $paymentMethod);
    }

    /**
     * Delete a stored payment method.
     */
    public function deletePaymentMethod(string $paymentMethodId): void
    {
        $this->ensureCashierSupports(Capability::PaymentMethodsDelete);

        $this->cashierProvider()->deletePaymentMethod($this, $paymentMethodId);
    }
}
