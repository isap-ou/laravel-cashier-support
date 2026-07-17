<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\PublishesWebhooksGateway;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * `cashier:webhook`, which used to be one command per driver.
 *
 * The references disagree here and the disagreement is the design: Stripe ships a
 * command because it has an API for creating endpoints; Paddle ships no Console
 * directory at all because it does not. So this is an interface a driver either
 * implements or does not — the fact IS the declaration — rather than a Capability flag
 * that could disagree with it.
 */
class WebhookCommandTest extends TestCase
{
    private PublishesWebhooksGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new PublishesWebhooksGateway;
        // Captured by value: Manager::extend() rebinds a non-static closure to itself.
        $gateway = $this->gateway;

        Cashier::extend('fake', fn (): PublishesWebhooksGateway => $gateway);
        config()->set('cashier-support.default', 'fake');
    }

    public function test_it_registers_the_url_the_app_actually_serves(): void
    {
        // The whole reason this command moved. The driver's version built its URL from
        // its own config key — and #47 deletes that key, so it would have gone on
        // registering a URL that 404s on every delivery, silently. The route is the only
        // source that cannot drift from where the webhook is really mounted.
        $this->artisan('cashier:webhook')->assertSuccessful();

        $this->assertSame(route('cashier.webhook', ['provider' => 'fake']), $this->gateway->registeredUrl);
        $this->assertStringContainsString('/webhook/cashier/fake', (string) $this->gateway->registeredUrl);
    }

    public function test_it_defaults_to_every_event_the_driver_handles(): void
    {
        $this->artisan('cashier:webhook')->assertSuccessful();

        $this->assertSame(['order.completed', 'order.failed'], $this->gateway->registeredEvents);
    }

    public function test_an_unknown_event_is_refused_and_nothing_is_registered(): void
    {
        // A typo the gateway accepts subscribes the endpoint to nothing, and nothing is
        // what you find out much later. The driver refuses it, because the catalogue is
        // native names only it knows; the command's job is to report that, not to own it.
        // One assertion, not two: expectsOutputToContain registers a Mockery expectation
        // per call, and the first match consumes the write — so two of them against the
        // same output LINE silently never both fire.
        $this->artisan('cashier:webhook', ['--events' => ['order.completed', 'order.nope']])
            ->expectsOutputToContain('Unknown webhook event [order.nope]. Known events: order.completed, order.failed')
            ->assertFailed();

        $this->assertNull($this->gateway->registeredUrl);
    }

    public function test_a_secret_that_comes_back_is_printed_with_a_warning(): void
    {
        // Revolut returns signing_secret once and never again, so it has to be shown —
        // and the warning is owed, because it lands in console and CI logs.
        $this->artisan('cashier:webhook')
            ->expectsOutputToContain('will appear in console/CI logs')
            ->expectsOutputToContain('whsec_fake')
            ->assertSuccessful();
    }

    public function test_a_gateway_that_issues_no_secret_is_a_success_not_a_failure(): void
    {
        // Stripe's case: its command prints no secret and says to fetch it from the
        // dashboard. A null secret means "this gateway does not hand it back here", which
        // is not an error — and this is why the result is not a plain string: a
        // Stripe-shaped driver would have to return '', the exact sentinel #42 removed.
        $this->gateway->secret = null;

        $this->artisan('cashier:webhook')
            ->expectsOutputToContain('does not return a signing secret here')
            ->assertSuccessful();
    }

    public function test_a_driver_that_cannot_register_says_so_and_does_not_pretend(): void
    {
        // No stub. "Throws beats quietly does nothing" — reporting success here would
        // leave an operator believing a webhook exists.
        $plain = new FakeGateway;
        Cashier::extend('plain', fn (): FakeGateway => $plain);

        $this->artisan('cashier:webhook', ['provider' => 'plain'])
            ->expectsOutputToContain('cannot register webhooks over the API')
            ->expectsOutputToContain('/webhook/cashier/plain')
            ->assertFailed();
    }

    public function test_an_unknown_provider_lists_the_ones_that_exist(): void
    {
        $this->artisan('cashier:webhook', ['provider' => 'nope'])
            ->expectsOutputToContain('Known drivers: fake')
            ->assertFailed();
    }

    public function test_a_refused_registration_is_reported_not_thrown(): void
    {
        $this->gateway->registrationFailure = new CashierException('The gateway said no.');

        $this->artisan('cashier:webhook')
            ->expectsOutputToContain('Webhook registration failed: The gateway said no.')
            ->assertFailed();
    }

    public function test_an_explicit_url_wins_over_the_route(): void
    {
        $this->artisan('cashier:webhook', ['--url' => 'https://example.test/hook'])->assertSuccessful();

        $this->assertSame('https://example.test/hook', $this->gateway->registeredUrl);
    }
}
