<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

/**
 * The gateway provider's webhook entry point.
 *
 * One method, because there is exactly one thing a driver still owns here: what a
 * delivery MEANS and what applying it does. The HTTP around it — the route, the
 * provider lookup, the event dispatches, the status codes — belongs to this package
 * and is the same for every gateway. A driver that ships its own webhook controller
 * is copying four generic steps for the sake of one specific one, and gets a fresh
 * chance to order them wrong each time; that is not a hypothetical, it is #42/#24.
 *
 * This used to be verifyWebhook() + parseWebhook(): WebhookPayload. Both are gone.
 * parseWebhook returned a typed DTO whose $event was a non-nullable 8-case agnostic
 * enum, so an event outside those 8 could not be represented, so the escape hatch
 * could not fire for precisely the events it existed for.
 */
interface WebhookHandler
{
    /**
     * Begin handling one incoming webhook delivery.
     *
     * Takes the raw body as bytes, not a decoded array: a signature covers the exact
     * bytes that arrived, and a decode/re-encode round trip does not reproduce them.
     *
     * This method only builds the delivery — it verifies nothing and applies nothing.
     * Both happen on the returned object, in the order this package's controller calls
     * them.
     *
     * @param  array<string, string>  $headers
     */
    public function webhook(string $content, array $headers): IncomingWebhook;
}
