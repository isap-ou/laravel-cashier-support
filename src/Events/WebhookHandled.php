<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a webhook has been applied to local state.
 *
 * Carries the same provider and RAW decoded body as WebhookReceived — the pair must
 * match, or an app has to read one webhook two different ways. Both references
 * dispatch the same array to both events.
 *
 * Unlike WebhookReceived, this one does NOT fire for an event the driver did not
 * map: nothing was handled, and saying otherwise would trade the old silence for a
 * lie. The reference draws the same line — it dispatches WebhookHandled only when a
 * handler existed (laravel/cashier's WebhookController.php:47-52).
 *
 * What keeps that true here is the bool from Contracts\IncomingWebhook::pipeline(),
 * which is that method_exists() check moved behind the contract. It has to be a
 * return value rather than an ordering: an unmapped event is REQUIRED to come back
 * normally from pipeline() instead of throwing, so "the driver did not throw" cannot
 * distinguish handled from unmapped. Without the bool this event would fire under
 * exactly the same conditions as WebhookReceived, carrying exactly the same data —
 * not a lie so much as a second name for one event.
 */
class WebhookHandled
{
    use SerializesModels;

    /**
     * @param  string  $provider  The driver name this delivery arrived for, as registered with Cashier::extend().
     * @param  array<string, mixed>  $payload  The provider's decoded webhook body, as sent.
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $payload,
    ) {}
}
