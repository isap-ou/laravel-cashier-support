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
     * @param  array<string, string>  $headers
     *
     * @throws UnexpectedWebhookEventException When the event is one this driver does not handle.
     */
    public function parseWebhook(string $payload, array $headers): WebhookPayload;
}
