<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\DTO\CheckoutRequest;

/**
 * Capability gating for the CheckoutOperations surface, composed into GuardedProvider.
 *
 * @internal Composed into Gateway\GuardedProvider, which is what Cashier::provider() returns. An app reaches this through the facade, never by name. Not public surface: outside the backward-compatibility promise in README.
 */
trait GuardsCheckout
{
    /**
     * {@inheritDoc}
     */
    public function checkout(Model $billable, CheckoutRequest $request): CheckoutSession
    {
        // Gated on the request's SHAPE — a price catalogue vs a raw amount — because that is
        // what the caller's intent is, and the two are separate capabilities.
        $this->ensure($request->capability());

        return $this->inner()->checkout($billable, $request);
    }
}
