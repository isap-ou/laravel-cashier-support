<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued listener that does nothing, for any event.
 *
 * Its only job is to be ShouldQueue: that is what forces the event through the
 * serializer and into the jobs table, where the payload can be inspected. It
 * takes `object` rather than a concrete event so one probe can sweep them all.
 */
class QueuedEventProbe implements ShouldQueue
{
    /**
     * Handle any event. The assertion is on the payload, not on this.
     */
    public function handle(object $event): void
    {
        //
    }
}
