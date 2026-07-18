<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\Enums\Capability;

/**
 * Capability gating for the CustomerOperations surface, composed into GuardedProvider.
 */
trait GuardsCustomers
{
    /**
     * {@inheritDoc}
     */
    public function createCustomer(Model $billable, CustomerDetails $details): Customer
    {
        $this->ensure(Capability::Customers);

        return $this->inner()->createCustomer($billable, $details);
    }

    /**
     * {@inheritDoc}
     */
    public function updateCustomer(Model $billable, CustomerDetails $details): Customer
    {
        $this->ensure(Capability::CustomersUpdate);

        return $this->inner()->updateCustomer($billable, $details);
    }

    /**
     * {@inheritDoc}
     */
    public function asCustomer(Model $billable): Customer
    {
        $this->ensure(Capability::Customers);

        return $this->inner()->asCustomer($billable);
    }
}
