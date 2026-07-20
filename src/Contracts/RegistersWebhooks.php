<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Isapp\CashierSupport\DTO\WebhookRegistration;
use Isapp\CashierSupport\Exceptions\CashierException;

/**
 * A gateway that can create its own webhook endpoint over the API.
 *
 * Deliberately NOT part of GatewayProvider, and deliberately not a Capability either
 * — the references disagree here, and the shape of their disagreement is the argument.
 * Stripe ships `cashier:webhook` (src/Console/WebhookCommand.php); Paddle ships no
 * Console directory at all, because a Paddle webhook is created by hand in the
 * dashboard. Not implementing this interface IS that declaration. A
 * Capability::WebhookRegistration flag would be a second source of truth sitting beside
 * "the method does not exist", and two sources of truth eventually disagree — whereas
 * `instanceof` cannot.
 *
 * Revolut has the API (POST /webhooks), so it lands on Stripe's side of the split.
 *
 * **One method is the whole contract, and that is settled rather than pending (#77).**
 * Listing the endpoints that exist and deleting one are operator work, done in the gateway's
 * dashboard. They are not here for the reason the split above already gives: this interface
 * exists so an app can CREATE the endpoint its own route serves, from the URL that route
 * reports, so the two cannot drift. Reading and deleting answer an operations question, not
 * an application one, and the app has no route to compare them against.
 *
 * The cost of deciding late is what makes it a decision now: there is no
 * Gateway\BaseGateway default to inherit here — that safety net covers GatewayProvider's
 * operations contracts, and this interface is deliberately outside them — so a method added
 * after 1.0 is an immediate fatal in every class that implements it. Adding one is therefore
 * a major release, and the shape is frozen with that understood.
 *
 * The consequence an operator should know: registering twice creates two endpoints, and the
 * gateway will deliver to both. Neither this package nor a driver can notice, because neither
 * can ask what already exists. `cashier:webhook` says so when it registers.
 */
interface RegistersWebhooks
{
    /**
     * Register a webhook endpoint at the gateway, and subscribe it to events.
     *
     * The caller supplies the URL — it comes from this package's named route, never from
     * a driver's own config, so it cannot drift from where the webhook is really mounted.
     *
     * Two rules the driver owns, because only the driver knows its own catalogue:
     *
     * - An **empty** $events means every event the GATEWAY documents — its whole
     *   catalogue — not the subset this driver knows how to apply. The catalogue is not
     *   on this contract: it is a list of native, gateway-specific names, and a
     *   support-side copy of it would be a second source of truth that goes stale.
     * - An event name that is not in the gateway's catalogue MUST throw, and the message
     *   must name the ones that exist. Passing it through would subscribe the endpoint to
     *   nothing at the gateway — which succeeds, silently, and is discovered much later by
     *   the webhooks that never arrive.
     *
     * **Delivered and applied are different questions, and this one is about delivery (#76).**
     * "Every event this driver handles" read as the applied subset, and registering that
     * subset is what makes Events\WebhookReceived unreachable: an app cannot listen for an
     * event the endpoint was never subscribed to, so the escape hatch #42 and #47 exist to
     * guarantee would be missing for exactly the events it was built for — the ones no driver
     * maps yet. Subscribe wide, apply narrow: what a driver does with a delivery it does not
     * map is a separate decision, made in its own synchronizer, and Events\WebhookHandled is
     * where that answer belongs.
     *
     * A driver that expects a signing secret back and does not get one MUST also throw
     * rather than return a WebhookRegistration with a null secret: null is reserved for
     * gateways that never issue one. Say in the message what the operator has to clean
     * up, because the endpoint exists by then.
     *
     * @param  array<int, string>  $events  Native event names; empty for all of them.
     *
     * @throws CashierException When an event name is unknown, when the gateway refuses the registration, or when it completes one without the secret it promised.
     */
    public function registerWebhook(string $url, array $events): WebhookRegistration;
}
