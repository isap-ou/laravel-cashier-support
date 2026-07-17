<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway;

use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Gateway\Defaults\RefusesCharges;
use Isapp\CashierSupport\Gateway\Defaults\RefusesCheckout;
use Isapp\CashierSupport\Gateway\Defaults\RefusesCustomers;
use Isapp\CashierSupport\Gateway\Defaults\RefusesInvoices;
use Isapp\CashierSupport\Gateway\Defaults\RefusesPaymentMethods;
use Isapp\CashierSupport\Gateway\Defaults\RefusesSubscriptions;
use Isapp\CashierSupport\Gateway\Defaults\RefusesWebhooks;
use ReflectionMethod;

/**
 * Everything a gateway does not do, already written — so that adding an operation to this
 * package stops being a fatal error in every driver.
 *
 * `GatewayProvider` bundles all seven operations interfaces and nothing shipped default
 * implementations, so one new contract method was an instant fatal in every driver: not a
 * deprecation, a driver that did not implement it stopped loading. That is why the features
 * needing a new method queued behind one another instead of landing independently. This is #28.
 *
 * **The fix is not to make the methods optional.** Segregating them into opt-in interfaces and
 * asking `$provider instanceof SupportsPause` was considered and rejected: drivers are
 * "drop-in replacements for each other", so an app that moves from one gateway to the next
 * would get `Call to undefined method` instead of a catchable CashierException, and would have
 * to ask `instanceof` at every call site — putting the gateway back in the caller's head,
 * which is the coupling this package exists to remove.
 *
 * A driver's throwing stubs were never the disease. They are how substitution works: every
 * gateway answers every method, either doing it or refusing in a typed, catchable way. The
 * only real complaint was that each driver hand-wrote them. Now support does.
 *
 * So: extend this, override what the gateway genuinely does, leave the rest. A method added
 * to a contract and to the matching Defaults trait in the same commit is inherited by every
 * driver that extends this — it keeps loading, and reports the new capability unsupported by
 * itself.
 *
 * **A class, not a trait, and that is not a style choice.** Gateway\ManagesLocalInvoices
 * already implements invoices()/findInvoice()/downloadInvoice(), and a class using two traits
 * that define the same method is a fatal error — "Trait method ... has not been applied ...
 * because of collision". Shipping the defaults as traits for a driver to mix in would force an
 * `insteadof` per collision, re-edited every time support added a method: the very BC break
 * this removes. Inheritance has no such problem — PHP resolves a method as own class, then
 * trait, then parent — so a driver's trait silently and correctly beats the default here.
 *
 * That also decides why the refusals live in Defaults\* traits composed here rather than as
 * opt-in mixins for drivers: grouped one-per-contract they stay readable and each new method
 * has an obvious home, but they are flattened into this class, so a driver still inherits them
 * and still avoids the collision. Using one directly from a driver walks back into it.
 *
 * The cost is stated plainly: a driver cannot extend anything else. That is the price of the
 * defaults, and it is why this class holds no state and no behaviour beyond refusing.
 */
abstract class BaseGateway implements GatewayProvider
{
    use RefusesCharges;
    use RefusesCheckout;
    use RefusesCustomers;
    use RefusesInvoices;
    use RefusesPaymentMethods;
    use RefusesSubscriptions;
    use RefusesWebhooks;

    /**
     * Capabilities no method can express, declared by the driver.
     *
     * Everything else is read off the code by supports(), so a driver cannot claim an
     * operation it never wrote. These eleven can't be: SubscriptionSwapImmediate and
     * SubscriptionSwapAtPeriodEnd are one swapSubscription(); SubscriptionPauseImmediate and
     * SubscriptionPauseAtPeriodEnd are one pauseSubscription(); CheckoutPrices and
     * CheckoutAmount are one checkout(); Trials/Quantity/Metadata/Taxes are setters on
     * SubscriptionBuilder, which is not this object at all; and Discounts backs no operation at
     * all — it is a fact about the shape of an invoice. See Enums\Capability::methods().
     *
     * Abstract on purpose. A default of `[]` would let a driver forget its swap timing and
     * silently report it unsupported — the drift this class exists to end.
     *
     * @return array<int, Capability>
     */
    abstract protected function declaredCapabilities(): array;

    /**
     * Final, for the reason Builders\GuardedSubscriptionBuilder is a final class: the gate is
     * not the driver's to make. An override here is a driver claiming an operation it never
     * wrote — the drift this whole mechanism removes — and it would be invisible, because a
     * lie about capabilities reads exactly like the truth until an app calls the method.
     *
     * Nothing legitimate is lost. What these two derive is a STRUCTURAL fact — whether the
     * method was overridden — and a structural fact cannot honestly vary at runtime. The
     * extension point is declaredCapabilities(), which stays abstract, is asked on every call,
     * and is free to be as dynamic as a driver likes. And the lock is not a wall: BaseGateway
     * is opt-in, so a gateway that genuinely needs its own supports() implements
     * Contracts\GatewayProvider directly, as drivers did before this class existed.
     *
     * The known limit, said out loud: an operation that is implemented but disabled per
     * account cannot report itself unsupported. That is deliberate — supports() answers "can
     * this gateway do this at all", and a runtime refusal is a CashierException, not a
     * capability.
     *
     * @return array<int, Capability>
     */
    final public function capabilities(): array
    {
        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $capability): bool => $this->supports($capability)
        ));
    }

    final public function supports(Capability $capability): bool
    {
        $methods = $capability->methods();

        if ($methods === []) {
            return in_array($capability, $this->declaredCapabilities(), true);
        }

        // Every method, not any: a gateway that lists invoices but cannot render one does
        // not support Invoices, and saying it does is how an app finds out at the worst
        // possible moment.
        foreach ($methods as $method) {
            if ($this->isDefaultImplementation($method)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this method is still a refusal from Defaults\*, rather than a real one.
     *
     * PHP reports a trait-provided method as declared by the USING class and an inherited one
     * as declared by the parent — which is exactly the distinction needed, and another reason
     * this is a class. The Defaults traits are flattened into BaseGateway, so they answer as
     * BaseGateway; a driver that overrides in its own body, or mixes in a trait that does
     * (ManagesLocalInvoices), answers as itself.
     */
    private function isDefaultImplementation(string $method): bool
    {
        return (new ReflectionMethod($this, $method))->getDeclaringClass()->getName() === self::class;
    }
}
