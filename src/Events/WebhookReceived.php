<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Isapp\CashierSupport\DTO\WebhookPayload;

/**
 * Dispatched when a verified webhook has been received and normalized,
 * before it is handled.
 */
class WebhookReceived
{
    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
