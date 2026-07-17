<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\CustomerOperations, refused.
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 */
trait RefusesCustomers
{
    public function createCustomer(Model $billable, array $options = []): Customer
    {
        throw UnsupportedOperationException::forCapability(Capability::Customers);
    }

    public function asCustomer(Model $billable): Customer
    {
        throw UnsupportedOperationException::forCapability(Capability::Customers);
    }
}
