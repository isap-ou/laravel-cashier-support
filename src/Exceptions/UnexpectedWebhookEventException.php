<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a gateway sends a webhook event the driver does not subscribe to,
 * or cannot translate into a WebhookPayload.
 *
 * Provider-agnostic on purpose: every gateway delivers events a given driver has
 * no interest in, and a caller of WebhookHandler::parseWebhook() must be able to
 * recognise "not for me" without knowing which driver it is talking to. It used
 * to be a driver-private type thrown from a contract method — undeclared, and
 * uncatchable without naming the driver.
 *
 * The webhook controller acknowledges such events (2xx) rather than failing them:
 * they are not errors, and a gateway that retries them learns nothing new.
 */
class UnexpectedWebhookEventException extends CashierException
{
    public static function forEvent(string $event): self
    {
        return new self("Unexpected webhook event [{$event}].");
    }
}
