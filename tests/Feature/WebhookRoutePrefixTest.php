<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Foundation\Application;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The webhook route under a configured prefix.
 *
 * Its own class because the prefix has to be set BEFORE the service provider boots — the
 * route is registered once, at boot, exactly as in a real app. Setting it mid-test and
 * re-requiring the route file registers a SECOND route under the same name instead of
 * moving the first, and then route() resolves whichever was registered first: a green
 * test proving nothing about a real boot.
 */
class WebhookRoutePrefixTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.webhook.prefix', 'hooks/pay');
        $app['config']->set('cashier-support.webhook.methods', ['POST', 'GET']);
    }

    public function test_a_gateway_that_does_not_use_post_can_be_configured_for(): void
    {
        // The capability a driver lost when this package took ownership of the route.
        // Both references hardcode POST, and both serve exactly one gateway — so a
        // gateway that verifies its endpoint with a GET had, briefly, no way to be
        // reached at all and no way to say so. Configured, not guessed at: nothing here
        // claims to know which gateway needs it.
        $gateway = new FakeGateway;
        Cashier::extend('fake', fn (): FakeGateway => $gateway);

        $this->call('GET', '/hooks/pay/fake')->assertOk()->assertSee('Webhook handled.');
    }

    public function test_the_driver_segment_survives_a_custom_prefix(): void
    {
        // Found by review. The config value used to be the whole path, so an operator who
        // read it as a prefix — which its name invites — and set "webhook/cashier"
        // registered a perfectly valid route with no {provider} to bind, and every
        // delivery from every gateway 500'd. It is a prefix now and routes/webhook.php
        // appends the segment, so there is nothing left to drop. Stripe splits it the
        // same way: a configurable prefix, a hardcoded segment.
        $gateway = new FakeGateway;
        Cashier::extend('fake', fn (): FakeGateway => $gateway);

        $this->call('POST', '/hooks/pay/fake', [], [], [], [], '{"event":"fake.event"}')
            ->assertOk()
            ->assertSee('Webhook handled.');
    }

    public function test_the_named_route_follows_the_prefix(): void
    {
        // This is what `cashier:webhook` registers with the gateway. If it did not track
        // the prefix, the command would register a URL that 404s on every delivery —
        // silently, which is the whole reason the command reads the route and not config.
        $this->assertSame('/hooks/pay/revolut', route('cashier.webhook', ['provider' => 'revolut'], false));
    }
}
