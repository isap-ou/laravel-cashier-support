<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Isapp\CashierSupport\CashierManager;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;
use Psr\Log\LoggerInterface;

/**
 * The webhook entry point for every driver.
 *
 * {provider} names the driver, exactly as it was registered with Cashier::extend().
 * Neither reference has anything like it — Stripe and Paddle each hardcode one
 * provider and express its identity as a namespace, which is also why they cannot be
 * installed side by side. A driver manager makes provider identity data, so it has to
 * travel in the URL.
 *
 * The order of the four steps below is the whole reason this class exists in the
 * support package rather than once per driver. laravel-cashier-revolut#24 was these
 * steps ordered wrong in a driver's own controller: WebhookReceived sat BELOW the call
 * that decides what an event means, and that call throws for the 14 of Revolut's 22
 * documented event types the driver does not map — so every one of them, including all
 * DISPUTE_*, reached no listener and vanished behind a 200. Four generic steps, one
 * driver-specific one, and each new driver got a fresh chance to interleave them wrong.
 * Now the order is here, once.
 *
 * It mirrors the reference step for step (laravel/cashier's WebhookController.php:42-58):
 * read the body, dispatch WebhookReceived, ask whether there is anything to do, do it,
 * dispatch WebhookHandled.
 */
class WebhookController
{
    /**
     * The manager is injected rather than reached through the Cashier facade. A facade
     * proxies through method-tags in a docblock, and those cannot carry throws
     * information — so static analysis sees provider()'s InvalidConfigurationException as
     * unthrown and calls the catch below dead. It is not: an unknown {provider} is the
     * likeliest request this public route will ever get.
     */
    public function __construct(
        private readonly CashierManager $cashier,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        if (! in_array($provider, $this->cashier->registeredDrivers(), true)) {
            // An unknown {provider} is a URL that does not exist, so it answers like one.
            // Nothing is logged at a level that alerts: this route is public, and anyone
            // may probe it with any name they like.
            //
            // Asked before resolving, not by catching: provider() raises the SAME
            // InvalidConfigurationException for "no such driver" and for "this driver's
            // factory is misconfigured". Catching it would answer 404 to a driver that
            // very much exists — telling the gateway the endpoint is gone, and skipping
            // the critical log below — which is the silence this controller exists to end.
            return new Response('Unknown provider.', 404);
        }

        try {
            $gateway = $this->cashier->provider($provider);
            $webhook = $gateway->webhook((string) $request->getContent(), $this->normalizeHeaders($request));
            $payload = $webhook->parse();
        } catch (WebhookVerificationException) {
            // A secret that is set but wrong — rotated at the gateway and not in .env,
            // or a sandbox key in production — refuses every webhook exactly like an
            // unset one, and it is the likelier mistake of the two. Refusing it silently
            // is what leaves an operator with no signal while the local mirror goes stale.
            $this->logger->warning('A webhook failed signature verification and was refused', [
                'provider' => $provider,
            ]);

            return new Response('Invalid signature.', 400);
        } catch (InvalidConfigurationException $exception) {
            return $this->refuseMisconfigured($provider, $exception);
        } catch (UnexpectedWebhookEventException $exception) {
            // Not an event at all. Acknowledged rather than refused: a body that cannot be
            // read will not become readable on retry. Logged, because a silent drop has to
            // be visible somewhere — that silence was #24.
            $this->logger->info('A webhook body could not be read as an event', [
                'provider' => $provider,
                'exception' => $exception->getMessage(),
            ]);

            return new Response('Webhook ignored.', 200);
        }

        // The escape hatch, and its position is the point: above every decision about
        // what this event means, below verification. An app reaches events we never
        // mapped here, and only here.
        event(new WebhookReceived($provider, $payload));

        try {
            $handled = $webhook->pipeline();
        } catch (InvalidConfigurationException $exception) {
            // The same defect one path over, and it is not hypothetical: applying calls
            // back into the gateway's API, so a driver with a signing secret but no API
            // key verifies fine and then dies here. Catching it only around parse() would
            // answer 500 to a plainly fixable misconfiguration — no critical line, and a
            // retry the gateway does not document. The driver's own controller learned
            // this and left a comment about it; this is that comment, kept.
            return $this->refuseMisconfigured($provider, $exception);
        }

        // Every OTHER failure propagates on purpose: applying failed for a reason nobody
        // can fix from a config file, so the delivery deserves the 5xx that makes the
        // gateway retry it into writes that are idempotent by design.
        if (! $handled) {
            // A delivery that had no effect — commonly an event this driver does not map,
            // but equally one it maps and found nothing to apply to. Acknowledged, so the
            // gateway stops retrying something no handler will ever accept — and harmless
            // in a way it was not before, because the hatch above already fired. No
            // WebhookHandled: nothing was handled.
            return new Response('Webhook ignored.', 200);
        }

        event(new WebhookHandled($provider, $payload));

        return new Response('Webhook handled.', 200);
    }

    /**
     * Refuse a webhook this driver is not configured to handle.
     *
     * A 4XX is a delivery failure gateways retry — the only answer that gives the event a
     * chance of surviving the fix, where an unhandled exception renders whatever the app's
     * error handler decides and buys a retry nobody documents.
     *
     * Critical because the alternative symptom is silence: every webhook refused, the
     * retries exhausted, and the subscriptions quietly stale.
     */
    private function refuseMisconfigured(string $provider, InvalidConfigurationException $exception): Response
    {
        $this->logger->critical('A gateway driver is not configured; the webhook was refused', [
            'provider' => $provider,
            'exception' => $exception->getMessage(),
        ]);

        return new Response('The gateway driver is not configured.', 400);
    }

    /**
     * Flatten the request's headers into the shape the WebhookHandler contract takes.
     *
     * Lives here rather than in each driver: turning a Request into a plain array is
     * not a fact about any gateway. Values are joined rather than picked, because a
     * repeated header is the sender's business and dropping one silently changes what
     * a signature is checked against.
     *
     * @return array<string, string>
     */
    private function normalizeHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = implode(', ', array_map('strval', $values));
        }

        return $headers;
    }
}
