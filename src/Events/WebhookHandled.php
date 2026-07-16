<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Isapp\CashierSupport\DTO\WebhookPayload;

/**
 * Dispatched after a webhook has been handled.
 */
class WebhookHandled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
