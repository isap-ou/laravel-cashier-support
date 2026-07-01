<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Hosted checkout operations at the gateway provider.
 */
interface CheckoutOperations
{
    /**
     * Create a hosted checkout session for the billable entity.
     *
     * @param  array<string, int>|string  $items  Price identifiers mapped to quantities, or a single price.
     * @param  array<string, mixed>  $options  Session options (success_url, cancel_url, mode, ...).
     *
     * @throws UnsupportedOperationException When the provider does not support hosted checkout.
     */
    public function checkout(Model $billable, array|string $items, array $options = []): CheckoutSession;
}
