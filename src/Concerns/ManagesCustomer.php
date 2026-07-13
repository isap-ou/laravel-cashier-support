<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Customer as CustomerRecord;

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

    /**
     * Whether the entity is already a customer at its gateway.
     *
     * Provider-neutral: the app no longer reaches for a driver-named column.
     */
    public function hasCustomerId(): bool
    {
        return $this->customerId() !== null;
    }

    /**
     * The local customer record for this entity's gateway, if any.
     *
     * A relation rather than a query so an app can eager-load it: this replaces
     * a column that was already hydrated on the billable row, and without it
     * every list of billables would issue one SELECT per row.
     *
     * @return MorphOne<CustomerRecord, $this>
     */
    public function cashierCustomer(): MorphOne
    {
        // Resolved once: the model registry and the provider column must agree,
        // or a model would be looked up for one driver and filtered by another.
        $driver = $this->cashierDriver() ?? Cashier::getDefaultDriver();

        return $this->morphOne(Cashier::customerModel($driver), 'owner')
            ->where('provider', $driver);
    }

    /**
     * The id the gateway assigned to this entity, if any.
     */
    public function customerId(): ?string
    {
        $driver = $this->cashierDriver() ?? Cashier::getDefaultDriver();

        // A driver that stores no customers is a legitimate driver. Asking
        // whether a billable is a customer must answer "no", not explode
        // because the driver never registered a model it never writes.
        if (! Cashier::hasModel('customer', $driver)) {
            return null;
        }

        /** @var CustomerRecord|null $record */
        $record = $this->relationLoaded('cashierCustomer')
            ? $this->getRelation('cashierCustomer')
            : $this->cashierCustomer()->first();

        return $record?->provider_id;
    }

    /**
     * The gateway customer for this entity, creating it only if it has none.
     *
     * @param  array<string, mixed>  $options
     */
    public function createOrGetCustomer(array $options = []): Customer
    {
        return $this->hasCustomerId()
            ? $this->asCustomer()
            : $this->createAsCustomer($options);
    }
}
