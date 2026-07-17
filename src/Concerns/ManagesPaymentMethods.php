<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\PaymentMethodType;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

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
     * Whether the entity currently has a default payment method.
     *
     * **This one asks the gateway; the reference's does not.** Stripe answers from a locally
     * cached column — `return (bool) $this->pm_type;` (ManagesPaymentMethods.php:48) — because
     * it mirrors the default onto the billable's own table. We cache nothing, so the honest
     * implementation is the round-trip, and it can throw where Stripe's would simply return
     * false: a gateway with no payment-method concept refuses PaymentMethodsList rather than
     * pretending the answer is "no". Catchable, and a drop-in gateway swap keeps working —
     * but do not put this in a loop.
     *
     * @throws UnsupportedOperationException When the provider cannot list payment methods.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return $this->defaultPaymentMethod() !== null;
    }

    /**
     * Whether the entity has at least one stored payment method, optionally of one type.
     *
     * $type is a Contracts\PaymentMethodType — a driver-owned enum — where the reference takes
     * a raw string (ManagesPaymentMethods.php:59), because there its strings ARE Stripe's own
     * wire values and this package has no such vocabulary to borrow. A plain string is still
     * accepted and compared against the enum's backing value, so an app that does not want to
     * name its driver's enum does not have to.
     *
     * @throws UnsupportedOperationException When the provider cannot list payment methods.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     */
    public function hasPaymentMethod(PaymentMethodType|string|null $type = null): bool
    {
        return $this->paymentMethodsOfType($type) !== [];
    }

    /**
     * Delete every stored payment method, optionally only those of one type.
     *
     * The account-closure operation, and the reason it is worth having rather than leaving to
     * the app: doing it by hand means listing, looping and deleting, which is exactly where a
     * caller forgets that the list is the gateway's and not a local table.
     *
     * Needs BOTH capabilities, and asks for them in the order it uses them: it lists first,
     * so a gateway that can delete a named method but cannot enumerate them refuses here —
     * naming the gate it actually lacks rather than the one that reads better.
     *
     * Stripe re-syncs its cached default afterwards (ManagesPaymentMethods.php:285). We keep
     * no such cache, so there is nothing to resync — the next read asks the gateway.
     *
     * @throws UnsupportedOperationException When the provider cannot list or delete payment methods.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     */
    public function deletePaymentMethods(PaymentMethodType|string|null $type = null): void
    {
        $methods = $this->paymentMethodsOfType($type);

        $this->ensureCashierSupports(Capability::PaymentMethodsDelete);

        foreach ($methods as $method) {
            $this->cashierProvider()->deletePaymentMethod($this, $method->id);
        }
    }

    /**
     * The stored payment methods, narrowed to one type when asked.
     *
     * Filtered here rather than at the gateway because paymentMethods() takes no filter: the
     * references' do, but theirs pass the type to an endpoint that understands it. Ours would
     * have to invent a filter every driver then reimplements — so the narrowing is the
     * caller's side of the boundary, over a DTO field that is already typed.
     *
     * @return array<int, PaymentMethod>
     *
     * @throws UnsupportedOperationException When the provider cannot list payment methods.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     */
    private function paymentMethodsOfType(PaymentMethodType|string|null $type): array
    {
        $methods = $this->paymentMethods();

        if ($type === null) {
            return $methods;
        }

        // Cast rather than compare ->value directly: BackedEnum permits int, and `1 === '1'`
        // is false, so a driver enum that ignored the string-backed requirement on
        // Contracts\PaymentMethodType would make this method answer a silent, confident "no"
        // instead of failing where someone would notice.
        $value = (string) ($type instanceof PaymentMethodType ? $type->value : $type);

        return array_values(array_filter(
            $methods,
            fn (PaymentMethod $method): bool => (string) $method->type->value === $value,
        ));
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
