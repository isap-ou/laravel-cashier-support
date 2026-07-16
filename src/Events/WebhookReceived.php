<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\WebhookPayload;

/**
 * Dispatched when a verified webhook has been received and normalized,
 * before it is handled.
 */
class WebhookReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
