<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Isapp\CashierSupport\Contracts\RegistersWebhooks;
use Isapp\CashierSupport\DTO\WebhookRegistration;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Testing\FakeGateway;
use Throwable;

/**
 * A gateway that can create its own webhook endpoint — Stripe's and Revolut's side of
 * the split. FakeGateway is the other side: Paddle's, where the endpoint is made by hand
 * in a dashboard and the interface is simply not implemented.
 *
 * That two fixtures differ only by `implements` is the argument for an interface over a
 * Capability flag: the declaration cannot drift from the fact, because it IS the fact.
 */
class PublishesWebhooksGateway extends FakeGateway implements RegistersWebhooks
{
    public ?string $registeredUrl = null;

    /** @var array<int, string>|null */
    public ?array $registeredEvents = null;

    public ?Throwable $registrationFailure = null;

    /**
     * Null models a gateway that never hands the secret back through its API — Stripe's
     * command prints none and sends the operator to the dashboard.
     */
    public ?string $secret = 'whsec_fake';

    /**
     * This fake's catalogue. A real driver's is its own enum of native event names.
     *
     * @var array<int, string>
     */
    public const EVENTS = ['order.completed', 'order.failed'];

    public function registerWebhook(string $url, array $events): WebhookRegistration
    {
        // Both contract rules, honoured the way a driver must: empty means the gateway's whole
        // catalogue — not the subset this driver applies (#76) — and a name outside that
        // catalogue throws rather than subscribing the endpoint to nothing.
        $events = $events === [] ? self::EVENTS : $events;

        foreach ($events as $event) {
            if (! in_array($event, self::EVENTS, true)) {
                throw new CashierException("Unknown webhook event [{$event}]. Known events: ".implode(', ', self::EVENTS));
            }
        }

        if ($this->registrationFailure !== null) {
            throw $this->registrationFailure;
        }

        $this->registeredUrl = $url;
        $this->registeredEvents = $events;

        return new WebhookRegistration(id: 'wh_fake', secret: $this->secret);
    }
}
