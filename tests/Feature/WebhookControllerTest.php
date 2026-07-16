<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\PublishesWebhooksGateway;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The webhook entry point, which now lives here rather than once per driver.
 *
 * This file is the home of laravel-cashier-revolut#24's acceptance. That bug was four
 * generic steps ordered wrong inside a driver's own controller, and the reason it could
 * not be tested away was that every driver got to re-make it. Proving the order here
 * proves it for every driver at once, which is the entire point of the move.
 */
class WebhookControllerTest extends TestCase
{
    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;

        // Captured by value, not reached through $this: Manager::extend() rebinds a
        // non-static closure to the manager (RebindsCallbacksToSelf), so $this inside an
        // extend() callback is CashierManager — not whoever wrote the callback.
        $gateway = $this->gateway;

        Cashier::extend('fake', fn (): FakeGateway => $gateway);
    }

    public function test_an_unknown_provider_is_a_404_and_reaches_no_driver(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        // The route is public and {provider} is attacker-chosen, so an unknown name has
        // to be cheap and boring: no driver resolved, nothing dispatched, no alert.
        $this->post('/webhook/cashier/nope', [])->assertNotFound();

        Event::assertNotDispatched(WebhookReceived::class);
        $this->assertSame([], $this->gateway->webhookCalls);
    }

    public function test_a_registered_but_misconfigured_driver_is_not_reported_as_unknown(): void
    {
        // Found by review, and green CI never would have: provider() raises the same
        // InvalidConfigurationException for "no such driver" and for "this driver's
        // factory is misconfigured". Catching it around the lookup answered 404 to a
        // driver that exists — telling the gateway the endpoint is gone, so it stops
        // retrying, while no critical line was logged for the operator. Both halves of
        // that are the silence this controller exists to end.
        Cashier::extend('broken', function (): never {
            throw InvalidConfigurationException::missingKey('cashier-broken.secret_key');
        });

        $response = $this->postWebhook('broken');

        $response->assertStatus(400)->assertSee('The gateway driver is not configured.');
        $response->assertDontSee('Unknown provider.');
    }

    public function test_a_body_that_fails_verification_reaches_no_listener(): void
    {
        // The other half of #24's acceptance, and it matters more now that the hatch is
        // wide: an unverified body is not an event, it is noise, and dispatching it would
        // let anyone who can reach the URL fabricate one. This is also why parse() must
        // stay ABOVE the dispatch — support fixes the order, the driver fixes the crypto.
        Event::fake([WebhookReceived::class, WebhookHandled::class]);
        $this->gateway->webhookParseFailure = WebhookVerificationException::invalidSignature();

        $this->postWebhook()->assertStatus(400)->assertSee('Invalid signature.');

        Event::assertNotDispatched(WebhookReceived::class);
        $this->assertSame(['parse'], $this->gateway->webhookCalls, 'Nothing may be applied after a failed verification.');
    }

    public function test_a_misconfigured_driver_is_refused_with_400_so_the_gateway_retries(): void
    {
        // A 4XX is a delivery failure the gateway retries — the only answer that gives the
        // event a chance of surviving the fix. A 5XX from an unhandled exception renders
        // whatever the app's error handler decides.
        $this->gateway->webhookParseFailure = InvalidConfigurationException::missingKey('cashier-fake.webhook.signing_secret');

        $this->postWebhook()->assertStatus(400)->assertSee('The gateway driver is not configured.');
    }

    public function test_a_body_that_is_not_an_event_is_acknowledged_and_reaches_no_listener(): void
    {
        // Acknowledged, not refused: a body that cannot be read will not become readable
        // on retry. But it must not reach a listener either — an empty array standing in
        // for content would be indistinguishable from a real unmapped event.
        Event::fake([WebhookReceived::class, WebhookHandled::class]);
        $this->gateway->webhookParseFailure = UnexpectedWebhookEventException::forEvent('');

        $this->postWebhook()->assertOk()->assertSee('Webhook ignored.');

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_an_unmapped_event_reaches_a_listener_and_is_never_called_handled(): void
    {
        // #24 itself. Revolut documents 22 event types and its driver maps 8; the other 14
        // — every DISPUTE_* among them — reached no listener at all and vanished behind a
        // 200. They arrive here now, and are still acknowledged so the gateway stops
        // retrying something no handler will accept.
        $received = [];
        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$received): void {
            $received[] = $event->payload;
        });
        Event::fake([WebhookHandled::class]);

        $this->gateway->webhookPayload = ['event' => 'DISPUTE_ACTION_REQUIRED', 'id' => 'dp_1'];
        $this->gateway->webhookHandled = false;

        $this->postWebhook()->assertOk()->assertSee('Webhook ignored.');

        $this->assertSame([['event' => 'DISPUTE_ACTION_REQUIRED', 'id' => 'dp_1']], $received);
        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function test_a_handled_event_dispatches_both_events_with_the_same_body(): void
    {
        /** @var array<string, mixed> $seen */
        $seen = [];
        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): void {
            $seen['received'] = $event->payload;
        });
        Event::listen(WebhookHandled::class, function (WebhookHandled $event) use (&$seen): void {
            $seen['handled'] = $event->payload;
        });

        $this->gateway->webhookPayload = ['event' => 'ORDER_COMPLETED', 'order_id' => 'ord_1'];

        $this->postWebhook()->assertOk()->assertSee('Webhook handled.');

        // The pair must agree, or an app has to read one webhook two ways.
        $this->assertSame($this->gateway->webhookPayload, $seen['received'] ?? null);
        $this->assertSame($this->gateway->webhookPayload, $seen['handled'] ?? null);
    }

    public function test_the_hatch_fires_after_parsing_and_before_applying(): void
    {
        // The assertion the whole contract exists for. Asserting that all three happened
        // would pass with the order inverted — which is exactly the shape #24 shipped in.
        Event::listen(WebhookReceived::class, function (): void {
            $this->gateway->webhookCalls[] = 'dispatch:received';
        });

        $this->postWebhook()->assertOk();

        $this->assertSame(['parse', 'dispatch:received', 'pipeline'], $this->gateway->webhookCalls);
    }

    public function test_two_drivers_are_told_apart_by_the_event_provider(): void
    {
        // The hole this closes. Both references answer "whose webhook is this?" with the
        // class — different namespaces per package — and we have one shared event, so
        // without $provider an app gets a provider-shaped array and no way to read it.
        $other = new FakeGateway;
        $other->webhookPayload = ['event_type' => 'transaction.completed'];
        Cashier::extend('other', fn (): FakeGateway => $other);

        /** @var array<int, string> $providers */
        $providers = [];
        Event::listen(WebhookReceived::class, function (WebhookReceived $event) use (&$providers): void {
            $providers[] = $event->provider;
        });

        $this->postWebhook()->assertOk();
        $this->postWebhook('other')->assertOk();

        $this->assertSame(['fake', 'other'], $providers);
    }

    public function test_a_misconfiguration_that_only_surfaces_while_applying_is_refused_too(): void
    {
        // The sibling of the verification path, and the driver whose controller this
        // replaced left a comment warning about exactly it: applying calls back into the
        // gateway's API, so a driver with a valid signing secret but no API key verifies
        // fine and only then dies. Catching InvalidConfigurationException around parse()
        // alone answers 500 to a one-line config fix — no critical line for the operator,
        // and a retry the gateway does not document.
        Event::fake([WebhookHandled::class]);
        $this->gateway->webhookPipelineFailure = InvalidConfigurationException::missingKey('cashier-fake.secret_key');

        $this->postWebhook()->assertStatus(400)->assertSee('The gateway driver is not configured.');

        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function test_a_failure_while_applying_is_not_swallowed(): void
    {
        // A synchronizer failure has to stay a 5xx: the gateway retries, and the driver's
        // writes are idempotent by design. Catching it here would silently drop the sync
        // and answer 200 — the failure mode this package keeps rediscovering.
        Event::fake([WebhookHandled::class]);
        $this->gateway->webhookPipelineFailure = new CashierException('The gateway is down.');

        $this->postWebhook()->assertStatus(500);

        Event::assertNotDispatched(WebhookHandled::class);
    }

    public function test_the_raw_body_and_headers_reach_the_driver_untouched(): void
    {
        // A signature covers the exact bytes that arrived. If the controller decoded and
        // re-encoded, verification would fail on any body whose formatting we did not
        // reproduce — so this pins that it hands over what it was given.
        $body = '{"event":"ORDER_COMPLETED",  "order_id":"ord_1"}';

        $this->call('POST', '/webhook/cashier/fake', [], [], [], [
            'HTTP_X_SIGNATURE' => 'v1=abc',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame($body, $this->gateway->lastWebhookContent);
        $this->assertSame('v1=abc', $this->gateway->lastWebhookHeaders['x-signature'] ?? null);
    }

    public function test_only_post_is_accepted_by_default(): void
    {
        // POST is the default because it is what both references hardcode — but each of
        // them serves one gateway, so it is a default rather than a law, and
        // cashier-support.webhook.methods is what a driver that differs is configured
        // with. Until this package owned the route, a driver declared its own.
        $this->call('GET', '/webhook/cashier/fake')->assertStatus(405);

        $this->assertSame([], $this->gateway->webhookCalls);
    }

    public function test_a_driver_that_cannot_register_webhooks_still_serves_them(): void
    {
        // RegistersWebhooks is about creating the endpoint, not receiving deliveries.
        // Nothing about the entry point may depend on it — Paddle ships no command at all
        // and still handles webhooks.
        $publishes = new PublishesWebhooksGateway;
        Cashier::extend('publishes', fn (): PublishesWebhooksGateway => $publishes);

        $this->postWebhook('publishes')->assertOk()->assertSee('Webhook handled.');
    }

    private function postWebhook(string $provider = 'fake'): TestResponse
    {
        return $this->call('POST', "/webhook/cashier/{$provider}", [], [], [], [], '{"event":"fake.event"}');
    }
}
