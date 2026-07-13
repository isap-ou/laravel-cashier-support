<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Hosted checkout operations at the gateway provider.
 */
interface CheckoutOperations
{
    /**
     * Create a hosted checkout session for the billable entity.
     *
     * The request states its own shape — a catalogue of prices, or an amount —
     * and the support layer has already gated it against what this gateway
     * declares, so an implementation only ever sees a shape it can honour.
     *
     * @throws UnsupportedOperationException When the provider cannot check out a request of this shape.
     * @throws InvalidArgumentException When the request is malformed (neither shape, both, or a non-positive amount).
     * @throws CashierException When the gateway call fails.
     */
    public function checkout(Model $billable, CheckoutRequest $request): CheckoutSession;
}
