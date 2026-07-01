<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Hosted checkout for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait HandlesCheckout
{
    use InteractsWithProvider;

    /**
     * Create a hosted checkout session for the entity.
     *
     * @param  array<string, int>|string  $items
     * @param  array<string, mixed>  $options
     */
    public function checkout(array|string $items, array $options = []): CheckoutSession
    {
        $this->ensureCashierSupports(Capability::Checkout);

        return $this->cashierProvider()->checkout($this, $items, $options);
    }
}
