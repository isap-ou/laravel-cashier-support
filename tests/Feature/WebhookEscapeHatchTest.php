<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * WebhookReceived is the escape hatch, and a hatch that only fits what we already
 * understand is not one.
 *
 * Both references dispatch it with the RAW decoded body, before any dispatch
 * decision, so an app can react to an event the package never mapped
 * (vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:45,
 * vendor/laravel/cashier-paddle/src/Http/Controllers/WebhookController.php:49).
 * We used to dispatch a typed DTO\WebhookPayload instead — whose $event was a
 * non-nullable 8-case enum, so for any event outside those 8 the payload could not
 * be CONSTRUCTED. There was nothing to dispatch, and no gateway's catalogue is a
 * subset of 8 agnostic cases: Revolut documents 22 event types and its driver maps
 * 8, so 14 vanished behind a 200 — every DISPUTE_* among them.
 *
 * The typing was not paying for itself either. Nothing in this package or in any
 * driver ever read $payload->event: meaning travels on the nine TYPED domain events
 * (PaymentSucceeded, SubscriptionCreated, SubscriptionRenewed, …), which carry the
 * billable and a real DTO. That is exactly the reference's split — typed events for
 * what we understand, one raw event for everything that arrives. The DTO on this
 * event was decoration over a channel whose entire job is to not be selective.
 */
class WebhookEscapeHatchTest extends TestCase
{
    public function test_webhook_received_carries_the_raw_provider_body(): void
    {
        /** @var array<string, mixed>|null $seen */
        $seen = null;

        // A real listener, not Event::fake(): the claim is that an app RECEIVES it.
        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): void {
            $seen = $event->payload;
        });

        // PAYOUT_INITIATED is a real documented Revolut event that no driver maps —
        // and under the old shape this line could not be written at all.
        event(new WebhookReceived(['event' => 'PAYOUT_INITIATED', 'id' => 'po_1']));

        $this->assertSame(['event' => 'PAYOUT_INITIATED', 'id' => 'po_1'], $seen);
    }

    public function test_any_event_shape_travels_including_ones_no_enum_knows(): void
    {
        $seen = [];

        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): void {
            $seen[] = $event->payload['event'] ?? null;
        });

        // The point of a raw payload: this list is not a contract, and it does not
        // have to be. A gateway that invents an event next year needs no release here.
        foreach (['DISPUTE_ACTION_REQUIRED', 'PAYOUT_FAILED', 'SOMETHING_INVENTED_IN_2027'] as $native) {
            event(new WebhookReceived(['event' => $native]));
        }

        $this->assertSame(['DISPUTE_ACTION_REQUIRED', 'PAYOUT_FAILED', 'SOMETHING_INVENTED_IN_2027'], $seen);
    }

    public function test_webhook_handled_carries_the_raw_body_too(): void
    {
        /** @var array<string, mixed>|null $seen */
        $seen = null;

        Event::listen(WebhookHandled::class, function (WebhookHandled $event) use (&$seen): void {
            $seen = $event->payload;
        });

        // The pair must match, or an app has to read one event two ways. The
        // references dispatch the same array to both.
        event(new WebhookHandled(['event' => 'ORDER_COMPLETED', 'order_id' => 'ord_1']));

        $this->assertSame(['event' => 'ORDER_COMPLETED', 'order_id' => 'ord_1'], $seen);
    }
}
