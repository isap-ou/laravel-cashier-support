<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Spatie\LaravelData\Data;

/**
 * A normalized, provider-agnostic webhook payload.
 *
 * Concrete providers translate their native webhook body into this shape.
 *
 * It is also the ceiling of the WebhookReceived escape hatch. Both references
 * dispatch that event with a RAW ARRAY before any dispatch decision, so any
 * event type travels to a listener; we dispatch this typed DTO instead, which
 * means an event this class cannot express is an event no app can react to.
 * That is why $event is nullable rather than merely convenient: no gateway's
 * catalogue is a subset of the agnostic cases, so "the driver did not map this"
 * is a normal state of the world, not an error.
 */
class WebhookPayload extends Data
{
    /**
     * @param  WebhookEvent|null  $event  The agnostic meaning, or null when the driver
     *                                    did not map this event onto one. Null is not a
     *                                    failure: it is the driver declining to assert a
     *                                    meaning it does not have. A listener that must
     *                                    know what happened reads $rawEvent.
     * @param  string  $rawEvent  The provider's native event name, always — the one
     *                            provider-specific field on an otherwise agnostic DTO,
     *                            and the only thing an unmapped event has to say for
     *                            itself. It stays populated for mapped events too:
     *                            several native events can share one agnostic case, and
     *                            the distinction survives nowhere else.
     * @param  array<string, mixed>  $data  The event's remaining data, as the driver
     *                                      chose to pass it — NOT normalized, and no
     *                                      agnostic shape is defined for it. It said
     *                                      "provider-agnostic event data" and that was
     *                                      never true: the one driver we have puts the
     *                                      whole raw decoded body here, and the one
     *                                      reader of it digs out a provider-native key.
     *                                      Read $event and $rawEvent for anything you
     *                                      intend to branch on; treat this as opaque
     *                                      unless you know which driver produced it.
     */
    public function __construct(
        public ?WebhookEvent $event,
        public string $rawEvent,
        public string $id,
        public array $data = [],
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
