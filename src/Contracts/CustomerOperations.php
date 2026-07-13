<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Customer management operations at the gateway provider.
 */
interface CustomerOperations
{
    /**
     * Create the billable entity as a customer at the provider.
     *
     * @param  Model  $billable  The billable model.
     * @param  array<string, mixed>  $options
     *
     * @throws UnsupportedOperationException When the provider has no customer records.
     * @throws CashierException When the gateway call fails.
     */
    public function createCustomer(Model $billable, array $options = []): Customer;

    /**
     * Retrieve the provider customer for the billable entity.
     *
     * @throws CustomerNotFoundException When the billable entity is not a customer.
     * @throws CashierException When the gateway call fails.
     */
    public function asCustomer(Model $billable): Customer;
}
