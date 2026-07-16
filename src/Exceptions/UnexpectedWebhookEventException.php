<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a webhook body cannot be read as an event at all — malformed, or
 * naming no event this driver can even repeat back.
 *
 * It deliberately does NOT cover an event the driver merely does not map. That
 * used to be its main job, and it was the escape hatch's lid: an unmapped event
 * died here, so WebhookReceived never fired and no app could react to anything
 * outside the agnostic catalogue. An unmapped event now travels as a normal
 * WebhookPayload with a null $event (see WebhookHandler::parseWebhook), which
 * leaves this type for the case where there is genuinely nothing to describe.
 *
 * Provider-agnostic on purpose: a caller of parseWebhook() must be able to
 * recognise "I could not read this" without knowing which driver it is talking
 * to. It used to be a driver-private type thrown from a contract method —
 * undeclared, and uncatchable without naming the driver.
 *
 * The webhook controller acknowledges such a body (2xx) rather than failing it:
 * a gateway that retries an unreadable body learns nothing new.
 */
class UnexpectedWebhookEventException extends CashierException
{
    /**
     * The body named no event this driver could even repeat back.
     *
     * Takes no event name because neither remaining case has one: that is the
     * whole difference from the type's old meaning. The factory it replaces,
     * forEvent(string $event), was built for the unmapped case — an event with a
     * perfectly good name — and once that stopped being an exception the only
     * strings left to hand it were empty ones.
     */
    public static function unreadableBody(): self
    {
        return new self('The webhook body could not be read as an event.');
    }
}
