<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a verified webhook has been received, before any decision about
 * what to do with it.
 *
 * It carries the provider's RAW decoded body, deliberately, and both references do
 * the same (vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:45,
 * vendor/laravel/cashier-paddle/src/Http/Controllers/WebhookController.php:49).
 * This is the universal escape hatch: an app reacts here to events the package
 * never mapped, so the payload must not be able to refuse one. It used to carry a
 * typed DTO\WebhookPayload whose $event was a non-nullable 8-case enum — which made
 * the DTO's type the hatch's ceiling, and put that ceiling below the floor: for any
 * event outside those 8, no payload could be constructed and nothing was dispatched
 * at all. No gateway's catalogue is a subset of 8 agnostic cases.
 *
 * The array is provider-shaped, and that is not a wart: an event nobody mapped has
 * no agnostic meaning to render, and inventing one would be the lie the hatch exists
 * to prevent. Agnostic meaning travels on the TYPED events — PaymentSucceeded,
 * SubscriptionCreated, SubscriptionRenewed and the rest — which carry the billable
 * and a real DTO. Typed events for what we understand, one raw event for everything
 * that arrives: that split is the reference's, and it is why no production code ever
 * branched on the old $payload->event.
 */
class WebhookReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  The provider's decoded webhook body, as sent.
     */
    public function __construct(
        public readonly array $payload,
    ) {}
}
