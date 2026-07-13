<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Exceptions\CustomerNotFoundException;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Customer as CustomerRecord;

/**
 * The local store of gateway customer identities, for drivers.
 *
 * The write side deliberately lives here rather than in the Billable concerns:
 * if createAsCustomer() wrote the row itself, a driver that had not registered a
 * 'customer' model would start throwing InvalidConfigurationException the moment
 * it created a customer. Support ships the table, the model and the read API;
 * the driver owns the write.
 *
 * The composing gateway supplies its driver name via driverName().
 */
trait ManagesCustomerRecords
{
    /**
     * The driver name the composing gateway is registered under.
     */
    abstract protected function driverName(): string;

    /**
     * The gateway customer id for a billable.
     *
     * @throws CustomerNotFoundException When the billable is not a customer yet.
     */
    protected function customerIdFor(Model $billable): string
    {
        $id = $this->customerIdOrNull($billable);

        if ($id === null) {
            throw CustomerNotFoundException::notCreated();
        }

        return $id;
    }

    /**
     * The gateway customer id for a billable, or null when it has none.
     */
    protected function customerIdOrNull(Model $billable): ?string
    {
        $id = $this->customerRecord($billable)?->provider_id;

        // An empty id is not an id. The column is a plain string, and a blank
        // one would sail through every is-it-a-customer check and then be sent
        // to the gateway as a path segment.
        return $id === '' ? null : $id;
    }

    /**
     * Record the identity the gateway assigned to a billable.
     *
     * Keyed on (owner, provider): a billable has at most one identity per
     * gateway, so persisting twice updates rather than duplicates.
     */
    protected function persistCustomerId(Model $billable, string $customerId, ?string $name = null, ?string $email = null): void
    {
        if ($customerId === '') {
            throw CustomerNotFoundException::notCreated();
        }

        // morphs('owner') is NOT NULL. Without this the insert would fail with a
        // raw QueryException — after the customer already exists at the gateway.
        if ($billable->getKey() === null) {
            throw new InvalidConfigurationException(
                'A billable must be saved before it can be recorded as a gateway customer.',
            );
        }

        $model = Cashier::customerModel($this->driverName());

        $model::query()->updateOrCreate(
            [
                'owner_type' => $billable->getMorphClass(),
                'owner_id' => $billable->getKey(),
                'provider' => $this->driverName(),
            ],
            [
                'provider_id' => $customerId,
                ...($name !== null ? ['name' => $name] : []),
                ...($email !== null ? ['email' => $email] : []),
            ],
        );
    }

    /**
     * The billable a gateway customer id belongs to — of any type.
     *
     * This is what a flat column on the app's users table could never do: an
     * order webhook carries only a customer id, and the entity behind it may be
     * a User, a Team, or anything else the app bills.
     */
    protected function resolveOwnerByCustomerId(?string $customerId): ?Model
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $model = Cashier::customerModel($this->driverName());

        /** @var CustomerRecord|null $record */
        $record = $model::query()
            ->where('provider', $this->driverName())
            ->where('provider_id', $customerId)
            ->first();

        return $record?->owner()->first();
    }

    private function customerRecord(Model $billable): ?CustomerRecord
    {
        $model = Cashier::customerModel($this->driverName());

        /** @var CustomerRecord|null */
        return $model::query()
            ->where('provider', $this->driverName())
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey())
            ->first();
    }
}
