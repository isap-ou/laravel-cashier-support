<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a webhook has been applied to local state.
 *
 * Carries the same RAW decoded body as WebhookReceived — the pair must match, or an
 * app has to read one webhook two different ways. Both references dispatch the same
 * array to both events.
 *
 * Unlike WebhookReceived, this one does NOT fire for an event the driver did not
 * map: nothing was handled, and saying otherwise would trade the old silence for a
 * lie. The reference draws the same line — it dispatches WebhookHandled only when a
 * handler existed (laravel/cashier's WebhookController.php:47-52).
 */
class WebhookHandled
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  The provider's decoded webhook body, as sent.
     */
    public function __construct(
        public readonly array $payload,
    ) {}
}
