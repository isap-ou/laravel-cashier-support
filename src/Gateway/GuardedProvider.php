<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Contracts\IncomingWebhook;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Gateway\Guards\GuardsCharges;
use Isapp\CashierSupport\Gateway\Guards\GuardsCheckout;
use Isapp\CashierSupport\Gateway\Guards\GuardsCustomers;
use Isapp\CashierSupport\Gateway\Guards\GuardsInvoices;
use Isapp\CashierSupport\Gateway\Guards\GuardsPaymentMethods;
use Isapp\CashierSupport\Gateway\Guards\GuardsSubscriptions;

/**
 * Wraps a gateway provider and gates every capability-bearing operation before
 * delegating to it.
 *
 * The gate lives here, at the one boundary every operation passes through, rather
 * than in each Billable concern or each Models\Subscription mutator. A concern (or
 * a model, or an app that overrides one) cannot forget to declare a feature
 * unsupported, because the check is not its to make — it is not in the calling code
 * at all. Cashier::provider() returns this wrapper, so `Cashier::provider()->charge()`
 * and `$user->subscription('default')->cancel()` are guarded identically, with no
 * ensureSupports() line to omit. Concerns outside GatewayProvider (webhook endpoint
 * registration) are resolved by name off the manager (Cashier::webhookRegistrar()), never by
 * reaching around the guard for a raw provider.
 *
 * This is GuardedSubscriptionBuilder's pattern raised from the builder to the whole
 * provider surface (the builder round-trips the facade because it holds no provider; this guard
 * holds the driver and asks it directly). The gating
 * is split one trait per operation contract under Gateway\Guards\, the way BaseGateway
 * composes Gateway\Defaults\ — add a method to a contract, touch the one group trait.
 * Setter-level gating stays in GuardedSubscriptionBuilder (a distinct boundary object the
 * provider never sees the setters of); GuardsSubscriptions::newSubscription() wraps its
 * result in that guard, so both guards are created at this single boundary.
 *
 * The thrown type is unchanged (UnsupportedOperationException), so a caller that
 * catches it, and every test that asserts it, is unaffected by where the gate moved.
 *
 * @internal The wrapper Cashier::provider() returns around every driver. Constructed by CashierManager, never by an app or a driver. Not public surface: outside the backward-compatibility promise in README.
 */
final class GuardedProvider implements GatewayProvider
{
    use GuardsCharges;
    use GuardsCheckout;
    use GuardsCustomers;
    use GuardsInvoices;
    use GuardsPaymentMethods;
    use GuardsSubscriptions;

    public function __construct(
        private readonly GatewayProvider $inner,
        private readonly ?string $driver = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function webhook(string $content, array $headers): IncomingWebhook
    {
        // No gate: a gateway that delivers a webhook self-evidently supports webhooks, and
        // the controller must be able to read one regardless of any declared capability.
        return $this->inner->webhook($content, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    /**
     * {@inheritDoc}
     */
    public function supports(Capability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    /**
     * The wrapped, unguarded gateway — used by the group traits to delegate once a gate passes.
     */
    private function inner(): GatewayProvider
    {
        return $this->inner;
    }

    /**
     * The driver name this wrapper gates against — used where a group trait must forward it
     * (GuardsSubscriptions passes it to the GuardedSubscriptionBuilder it creates).
     */
    private function guardDriver(): ?string
    {
        return $this->driver;
    }

    /**
     * Refuse an operation the wrapped provider does not support, before it is delegated.
     *
     * The one mechanism every group trait gates through. It asks the wrapped driver directly —
     * the guard already holds it — rather than round-tripping the facade and the manager's
     * registry to re-resolve the same object on every call. Same refusal
     * (UnsupportedOperationException) CashierManager::ensureSupports() raises, so nothing a caller
     * catches changes; this just keeps the guard off the Facades layer.
     */
    private function ensure(Capability $capability): void
    {
        if (! $this->inner->supports($capability)) {
            throw UnsupportedOperationException::forCapability($capability);
        }
    }

    /**
     * Refuse a subscription create/swap when the owner declares tax rates the provider
     * cannot honour.
     *
     * Mirrors what Concerns\HandlesTaxes::ensureTaxRatesSupported() did before gating moved
     * here: the rates are the billable's own declaration, so a billable that declares none —
     * or one with no tax surface at all — never trips it. Read defensively: a MorphTo owner
     * is typed Model, and only a Billable carries taxRates().
     */
    private function ensureTaxRatesSupported(Model $billable): void
    {
        $taxRates = method_exists($billable, 'taxRates') ? $billable->taxRates() : [];
        $priceTaxRates = method_exists($billable, 'priceTaxRates') ? $billable->priceTaxRates() : [];

        if ($taxRates === [] && $priceTaxRates === []) {
            return;
        }

        $this->ensure(Capability::Taxes);
    }
}
