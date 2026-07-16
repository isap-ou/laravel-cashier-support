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
     * - An **empty** $events means "every event this driver handles". The catalogue is
     *   not on this contract: it is a list of native, gateway-specific names, and a
     *   support-side copy of it would be a second source of truth that goes stale.
     * - An event name the driver does not handle MUST throw, and the message must name
     *   the ones that exist. Passing it through would subscribe the endpoint to nothing
     *   at the gateway — which succeeds, silently, and is discovered much later by the
     *   webhooks that never arrive.
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
