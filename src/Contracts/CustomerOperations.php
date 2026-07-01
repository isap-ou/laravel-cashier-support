<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;

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
     */
    public function createCustomer(Model $billable, array $options = []): Customer;

    /**
     * Retrieve the provider customer for the billable entity.
     *
     * @throws CustomerNotFoundException When the billable entity is not a customer.
     */
    public function asCustomer(Model $billable): Customer;
}
