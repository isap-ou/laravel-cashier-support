<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Isapp\CashierSupport\DTO\WebhookPayload;

/**
 * Dispatched after a webhook has been handled.
 */
class WebhookHandled
{
    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
