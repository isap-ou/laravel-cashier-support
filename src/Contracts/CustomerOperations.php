<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
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
     * @param  CustomerDetails  $details  Already resolved by the concern — never an app's raw bag.
     *
     * @throws UnsupportedOperationException When the provider has no customer records.
     * @throws CashierException When the gateway call fails.
     */
    public function createCustomer(Model $billable, CustomerDetails $details): Customer;

    /**
     * Change what the provider knows about an existing customer.
     *
     * A null field on $details means "leave it alone", not "clear it": the caller named the
     * fields it meant, and overwriting the rest is how a sync silently destroys data.
     *
     * @param  Model  $billable  The billable model.
     * @param  CustomerDetails  $details  Only the fields the caller asked to change.
     *
     * @throws UnsupportedOperationException When the provider cannot change a customer.
     * @throws CustomerNotFoundException When the billable entity is not a customer.
     * @throws CashierException When the gateway call fails.
     */
    public function updateCustomer(Model $billable, CustomerDetails $details): Customer;

    /**
     * Retrieve the provider customer for the billable entity.
     *
     * @throws CustomerNotFoundException When the billable entity is not a customer.
     * @throws CashierException When the gateway call fails.
     */
    public function asCustomer(Model $billable): Customer;
}
