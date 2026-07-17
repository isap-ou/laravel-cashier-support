<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
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
     * The name this entity goes by at the gateway.
     *
     * Override it when the model keeps its name somewhere else — that is the whole point of
     * the hook. Without one, a driver had no way to learn where a name lives except to reach
     * into the app's model and guess an attribute, which is the coupling this package exists
     * to remove. Both references have the same seam under their own prefix
     * (`ManagesCustomer.php:195` Stripe, `:95` Paddle).
     *
     * The is_string() narrowing is ours; neither reference has it, and checking why is worth
     * more than copying. `Model::__get()` returns `mixed`, so an attribute that is present but
     * an int — or an unresolved relation — TypeErrors on its way out of a `?string` method.
     * Stripe's stripeName() (`:195`) declares no return type at all, so mixed is genuinely free
     * there. But stripeEmail() (`:205`) DOES declare `?string` and still returns
     * `$this->email ?? null`: the guard is missing where the type would need it, which argues
     * for narrowing rather than against it. (A merely *absent* attribute needs no guard —
     * Eloquent returns null for one — so Paddle's bare `$this->name` (`:97`) is not a bug,
     * just untyped.)
     */
    public function cashierName(): ?string
    {
        return is_string($this->name) ? $this->name : null;
    }

    /**
     * The email this entity goes by at the gateway.
     *
     * See cashierName(); same seam, same narrowing, and `:205` is the reference line its note
     * argues from (`ManagesCustomer.php:205` Stripe, `:105` Paddle).
     */
    public function cashierEmail(): ?string
    {
        return is_string($this->email) ? $this->email : null;
    }

    /**
     * Create the entity as a customer at the provider.
     *
     * Fills in what the model knows and lets an explicit option win — which is exactly what
     * both references do on create (Stripe `ManagesCustomer.php:72-94`, Paddle `:21-25`).
     * Create can do this safely because there is no prior state to overwrite; update cannot,
     * which is why updateCustomer() does not.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When a name or email in $options is not a string.
     */
    public function createAsCustomer(array $options = []): Customer
    {
        $this->ensureCashierSupports(Capability::Customers);

        $details = CustomerDetails::fromOptions($options);

        return $this->cashierProvider()->createCustomer($this, new CustomerDetails(
            name: $details->name ?? $this->cashierName(),
            email: $details->email ?? $this->cashierEmail(),
            options: $details->options,
        ));
    }

    /**
     * Change what the provider knows about this customer.
     *
     * **Only what was asked for.** The hooks are deliberately not consulted here: an update
     * carries prior state, so filling in an unmentioned field would quietly overwrite whatever
     * is at the gateway with whatever the model happens to hold. Call syncCustomerDetails()
     * when overwriting is the point. Stripe draws the same line — `updateStripeCustomer()`
     * (`:116`) is a bare passthrough, `syncStripeCustomerDetails()` (`:266`) is the one that
     * reads the hooks.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When a name or email in $options is not a string.
     */
    public function updateCustomer(array $options = []): Customer
    {
        $this->ensureCashierSupports(Capability::CustomersUpdate);

        return $this->cashierProvider()->updateCustomer($this, CustomerDetails::fromOptions($options));
    }

    /**
     * Push what this model knows about itself to the provider.
     *
     * The acceptance criterion of #36: a user changes their email in the app, this makes the
     * gateway agree. It is not an alias of updateCustomer() — it means "make the gateway match
     * my model", which is the one case where overwriting every field is intended.
     *
     * Note the drift it fixes is only one-way. Nothing here reconciles a change made AT the
     * gateway; that is an inbound webhook and a driver's concern.
     */
    public function syncCustomerDetails(): Customer
    {
        $this->ensureCashierSupports(Capability::CustomersUpdate);

        return $this->cashierProvider()->updateCustomer($this, new CustomerDetails(
            name: $this->cashierName(),
            email: $this->cashierEmail(),
        ));
    }

    /**
     * Change this entity's customer at the provider, creating it if it has none.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When a name or email in $options is not a string.
     */
    public function updateOrCreateCustomer(array $options = []): Customer
    {
        return $this->hasCustomerId()
            ? $this->updateCustomer($options)
            : $this->createAsCustomer($options);
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
