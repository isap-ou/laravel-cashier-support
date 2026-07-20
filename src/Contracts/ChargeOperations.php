<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;

/**
 * One-off charge and refund operations.
 */
interface ChargeOperations
{
    /**
     * Charge the billable entity for the given amount.
     *
     * The returned Payment may still be incomplete — e.g. `requires_action` for 3DS/SCA. A
     * driver returns that state as data; `Concerns\PerformsCharges::charge()` is what turns it
     * into a catchable `IncompletePaymentException`.
     *
     * **A charge is NOT idempotent unless you make it one, and the failure is money.** Neither
     * reference documents this at the Cashier level, so it is stated here rather than left to
     * each driver's README: pass `$options['idempotency_key']` — a value stable across the
     * caller's OWN retries — and a driver that has any deduplication mechanism must key it on
     * that. Without it, a retry is a second charge.
     *
     * The realistic way to lose money here is a queued job: `POST` succeeds at the gateway, the
     * worker dies before returning, Laravel retries the job, and the customer is charged twice.
     * A key minted per request cannot help — it protects the HTTP transport's own retry and
     * nothing above it — which is why this has to come from the caller, who is the only one who
     * knows what "the same charge" means. Use the order/cart id, not a fresh uuid.
     *
     * Support cannot supply a default: whether the gateway deduplicates at all is the driver's
     * fact, and inventing one here would make the same call safe or unsafe depending on which
     * driver is installed. A driver whose gateway has no mechanism should say so in its README.
     *
     * @param  int  $amount  Amount in minor units (cents).
     * @param  string  $paymentMethod  The payment method identifier.
     * @param  array<string, mixed>  $options  `idempotency_key` is the conventional key; see above.
     *
     * @throws PaymentFailedException When the charge is declined.
     * @throws CustomerNotFoundException When the billable entity is not a customer at the provider.
     * @throws CashierException When the gateway call fails.
     * @throws InvalidArgumentException When the amount is not positive.
     */
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment;

    /**
     * Refund a previous payment.
     *
     * Carries the same hazard as charge(), in the same direction: without
     * `$options['idempotency_key']`, a retried refund is a second refund. Money leaves either
     * way — a double charge and a double refund are the same defect pointing opposite ways.
     *
     * @param  string  $paymentId  The identifier of the payment to refund.
     * @param  array<string, mixed>  $options  `idempotency_key` is the conventional key; see charge().
     *
     * @throws PaymentFailedException When the provider rejects the refund.
     * @throws CashierException When the gateway call fails.
     */
    public function refund(Model $billable, string $paymentId, array $options = []): Refund;
}
