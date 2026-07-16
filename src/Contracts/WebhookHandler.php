<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;

/**
 * Webhook verification and translation at the gateway provider.
 */
interface WebhookHandler
{
    /**
     * Verify the authenticity of an incoming webhook request.
     *
     * @param  array<string, string>  $headers
     *
     * @throws WebhookVerificationException When the signature cannot be verified.
     * @throws InvalidConfigurationException When the driver has no signing secret to verify against.
     */
    public function verifyWebhook(string $payload, array $headers): void;

    /**
     * Translate a raw provider webhook body into a normalized payload.
     *
     * An event this driver does not map is NOT a failure and must not throw: return
     * a payload with a null $event and the provider's native name in $rawEvent. This
     * is the contract's half of the WebhookReceived escape hatch — while "I do not
     * map this" was an exception, a conforming driver had to drop the event before
     * any listener could ever see it, and no app could react to anything outside the
     * agnostic catalogue. Deciding what to DO with an unmapped event is the caller's
     * business; refusing to describe it is not.
     *
     * @param  array<string, string>  $headers
     *
     * @throws UnexpectedWebhookEventException When the body cannot be read as an event at
     *                                         all — malformed, or naming no event. Not for
     *                                         an event that is merely unmapped.
     */
    public function parseWebhook(string $payload, array $headers): WebhookPayload;
}
