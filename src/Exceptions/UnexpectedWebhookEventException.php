<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a gateway sends a webhook body the driver cannot read as an event at all.
 *
 * Note what this is NOT, because it used to be both and the wider half was the bug:
 * an event the driver simply does not map is not this. That case returns false from
 * Contracts\IncomingWebhook::pipeline() and never throws. While this exception also
 * meant "not subscribed to", a conforming driver had to throw for the majority of a
 * gateway's catalogue — Revolut documents 22 event types and our driver maps 8 — and
 * the throw landed above the escape hatch, so those events reached no listener at all.
 * That was isap-ou/laravel-cashier-support#42 / laravel-cashier-revolut#24.
 *
 * What survives is the narrow, real case: a body with no event name, or one that is
 * not an object. There is nothing to hand a listener and nothing to apply.
 *
 * Provider-agnostic on purpose: a caller must be able to recognise "this is not an
 * event" without knowing which driver it is talking to. It used to be a driver-private
 * type thrown from a contract method — undeclared, and uncatchable without naming the
 * driver.
 *
 * The webhook controller acknowledges these (2xx) rather than failing them: a body
 * that cannot be read will not become readable on retry, and a gateway that retries
 * it learns nothing new.
 */
class UnexpectedWebhookEventException extends CashierException
{
    public static function forEvent(string $event): self
    {
        return new self("Unexpected webhook event [{$event}].");
    }
}
