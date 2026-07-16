<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Isapp\CashierSupport\Events\SubscriptionCreated;

/**
 * A queued listener — the shape that grants access on SubscriptionCreated.
 *
 * It records what the billable looked like at the moment the JOB RAN, not at
 * the moment the event was dispatched. The gap between those two is the whole
 * point: a listener that deserializes a snapshot reports the stale value.
 */
class RecordingBillableListener implements ShouldQueue
{
    /**
     * The billable's name as seen from inside the queued job.
     */
    public static ?string $seenName = null;

    /**
     * Whether the billable arrived as an Eloquent model at all.
     */
    public static bool $seenModel = false;

    /**
     * Reset the recorded state between tests.
     */
    public static function reset(): void
    {
        static::$seenName = null;
        static::$seenModel = false;
    }

    /**
     * Handle the event.
     */
    public function handle(SubscriptionCreated $event): void
    {
        static::$seenModel = $event->billable instanceof User;
        static::$seenName = $event->billable->getAttribute('name');
    }
}
