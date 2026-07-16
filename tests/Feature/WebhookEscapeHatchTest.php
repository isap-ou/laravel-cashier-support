<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * WebhookReceived is the escape hatch, and this file pins the ONE thing about it
 * this package can pin: that its payload cannot refuse an event.
 *
 * Be clear about what is NOT tested here. Support ships no webhook controller —
 * nothing in src/ dispatches these events — so "an unmapped event reaches a
 * listener" cannot be asserted from this package at all. That acceptance test
 * lives in the driver (cashier-revolut's WebhookControllerTest), where there is a
 * request to post and an ordering to get wrong. A test here that constructed the
 * event by hand and asserted the array came back out would be asserting that PHP
 * arrays hold values, and would stay green against the exact bug this change is
 * about.
 *
 * So: a type assertion, deliberately, and one that would have caught the bug at
 * its source.
 *
 * The bug was that these events took a typed DTO\WebhookPayload whose $event was a
 * non-nullable 8-case enum — which made the DTO's type the hatch's ceiling, and put
 * that ceiling below the floor: for an event outside those 8 no payload could be
 * CONSTRUCTED, so nothing was dispatched at all. Revolut documents 22 event types
 * and its driver maps 8, so 14 vanished behind a 200 — every DISPUTE_* among them.
 * No gateway's catalogue is a subset of 8 agnostic cases.
 *
 * Both references dispatch a RAW array here, before any dispatch decision, for
 * exactly this reason (vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:45,
 * vendor/laravel/cashier-paddle/src/Http/Controllers/WebhookController.php:49).
 * Agnostic meaning is not lost by going raw: it travels on the nine TYPED events
 * (PaymentSucceeded, SubscriptionCreated, SubscriptionRenewed, …), which carry the
 * billable and a real DTO. Typed events for what we understand, one raw event for
 * everything that arrives.
 */
class WebhookEscapeHatchTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function hatchEvents(): array
    {
        return [
            'WebhookReceived' => [WebhookReceived::class],
            'WebhookHandled' => [WebhookHandled::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('hatchEvents')]
    public function test_the_payload_is_an_unconstrained_array_not_a_typed_dto(string $class): void
    {
        $payload = $this->payloadParameter($class);

        $type = $payload->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(
            'array',
            $type->getName(),
            "[{$class}]'s payload is typed [{$type->getName()}]. A type is a filter, and this "
            .'event must not filter: it fires for every verified webhook, including ones no '
            .'enum in this package knows. A DTO here cannot be constructed for an event the '
            .'driver did not map, so the event is not dispatched and the app never learns.',
        );
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('hatchEvents')]
    public function test_the_payload_is_required_so_a_driver_cannot_dispatch_an_empty_one(string $class): void
    {
        // No default: "some webhook happened" is not a signal an app can act on, and
        // the array is the entire content of these events.
        $this->assertFalse($this->payloadParameter($class)->isDefaultValueAvailable());
    }

    private function payloadParameter(string $class): ReflectionParameter
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        $this->assertNotNull($constructor, "[{$class}] has no constructor.");

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === 'payload') {
                return $parameter;
            }
        }

        $this->fail("[{$class}] has no \$payload parameter.");
    }
}
