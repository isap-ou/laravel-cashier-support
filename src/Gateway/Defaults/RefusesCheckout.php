<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\CheckoutOperations, refused.
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 *
 * @internal Composed into Gateway\BaseGateway, which a driver extends — never used directly (two traits defining one method is a fatal collision; see BaseGateway's docblock). Not public surface: outside the backward-compatibility promise in README.
 */
trait RefusesCheckout
{
    /**
     * The refusal names the SHAPE the caller asked for — prices or amount — which is what
     * DTO\CheckoutRequest::capability() already answers, so this does not decide it twice.
     * One gateway takes catalogue price ids and another takes an amount; both are
     * legitimate, and "checkout is unsupported" would be a lie to a caller whose only
     * mistake was asking for the other one.
     */
    public function checkout(Model $billable, CheckoutRequest $request): CheckoutSession
    {
        throw UnsupportedOperationException::forCapability($request->capability());
    }
}
