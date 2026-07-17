<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\CustomerOperations, refused.
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 */
trait RefusesCustomers
{
    public function createCustomer(Model $billable, CustomerDetails $details): Customer
    {
        throw UnsupportedOperationException::forCapability(Capability::Customers);
    }

    /**
     * Refuses CustomersUpdate, not Customers — a gateway that can create a customer but never
     * change one is a real gateway, and telling the app it has no customers at all would be a
     * lie it cannot act on.
     */
    public function updateCustomer(Model $billable, CustomerDetails $details): Customer
    {
        throw UnsupportedOperationException::forCapability(Capability::CustomersUpdate);
    }

    public function asCustomer(Model $billable): Customer
    {
        throw UnsupportedOperationException::forCapability(Capability::Customers);
    }
}
