<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The escape hatch, and whether it can be opened at all.
 *
 * WebhookReceived is meant to be universal: both references dispatch it before
 * any dispatch decision, precisely so an app can react to events the package
 * never mapped (vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:45,
 * vendor/laravel/cashier-paddle/src/Http/Controllers/WebhookController.php:49).
 * They dispatch a raw array, so any event type travels. We dispatch a typed DTO
 * — which means the DTO's type IS the hatch's ceiling, and nothing else can
 * raise it.
 *
 * That ceiling used to sit below the floor: $event was a non-nullable
 * WebhookEvent, an 8-case closed enum with no case meaning "the driver did not
 * map this". No gateway's catalogue is a subset of 8 agnostic cases — Revolut
 * documents 22 event types and its driver maps 8 — so for the other 14 no valid
 * payload existed, and therefore no WebhookReceived could be dispatched at all.
 * The events vanished behind a 200.
 */
class UnmappedWebhookEventTest extends TestCase
{
    public function test_the_agnostic_catalogue_has_no_case_for_an_unmapped_event(): void
    {
        // Why null carries the meaning rather than a WebhookEvent case: every case
        // in the enum is a meaning a driver asserts, and "I did not recognise this"
        // is not one of them. PAYOUT_INITIATED is a real documented Revolut event,
        // not a stand-in.
        $this->assertNull(WebhookEvent::tryFrom('PAYOUT_INITIATED'));
    }

    public function test_a_driver_can_construct_a_payload_for_an_event_it_did_not_map(): void
    {
        $payload = new WebhookPayload(
            event: null,
            rawEvent: 'PAYOUT_INITIATED',
            id: 'po_1',
        );

        $this->assertNull($payload->event);

        // The native name is the whole point of being able to construct this. A
        // payload that only says "something unmapped happened" tells a listener
        // nothing it can act on.
        $this->assertSame('PAYOUT_INITIATED', $payload->rawEvent);
    }

    public function test_an_unmapped_event_reaches_a_webhook_received_listener(): void
    {
        /** @var WebhookPayload|null $seen */
        $seen = null;

        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): void {
            $seen = $event->payload;
        });

        // A real listener, not Event::fake(): the claim is that an app RECEIVES
        // the event, and a fake would assert only that it was dispatched.
        event(new WebhookReceived(new WebhookPayload(
            event: null,
            rawEvent: 'DISPUTE_ACTION_REQUIRED',
            id: 'dis_1',
            data: ['id' => 'dis_1'],
        )));

        $this->assertInstanceOf(WebhookPayload::class, $seen);
        $this->assertNull($seen->event);
        $this->assertSame('DISPUTE_ACTION_REQUIRED', $seen->rawEvent);

        // The dispute the app could not see before. DISPUTE_ACTION_REQUIRED is the
        // one with a deadline attached, which is why "it is only a missing event"
        // undersells it.
        $this->assertSame(['id' => 'dis_1'], $seen->data);
    }

    public function test_a_mapped_event_still_carries_its_native_name(): void
    {
        // rawEvent is not an unmapped-only field. A listener that wants to tell
        // ORDER_PAYMENT_DECLINED from ORDER_PAYMENT_FAILED cannot: both map onto
        // WebhookEvent::PaymentFailed, and the distinction only survives here.
        $payload = new WebhookPayload(
            event: WebhookEvent::PaymentSucceeded,
            rawEvent: 'ORDER_COMPLETED',
            id: 'ord_1',
        );

        $this->assertSame(WebhookEvent::PaymentSucceeded, $payload->event);
        $this->assertSame('ORDER_COMPLETED', $payload->rawEvent);
    }
}
