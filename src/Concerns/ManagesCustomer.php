<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Customer management for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait ManagesCustomer
{
    use InteractsWithProvider;

    /**
     * Create the entity as a customer at the provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function createAsCustomer(array $options = []): Customer
    {
        $this->ensureCashierSupports(Capability::Customers);

        return $this->cashierProvider()->createCustomer($this, $options);
    }

    /**
     * Retrieve the provider customer for the entity.
     */
    public function asCustomer(): Customer
    {
        $this->ensureCashierSupports(Capability::Customers);

        return $this->cashierProvider()->asCustomer($this);
    }
}
