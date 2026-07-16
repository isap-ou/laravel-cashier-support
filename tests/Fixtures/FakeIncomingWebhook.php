<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Isapp\CashierSupport\Contracts\IncomingWebhook;

/**
 * A scriptable webhook delivery. Every knob lives on the FakeGateway that made it, so
 * a test configures the driver and the controller resolves it the normal way.
 *
 * It records each step into FakeGateway::$webhookCalls rather than just answering,
 * because what this contract is FOR is the order of those steps — a fake that only
 * returns the right values would pass just as happily with the order inverted, which
 * is the bug (#24) this whole design exists to make impossible.
 */
class FakeIncomingWebhook implements IncomingWebhook
{
    public function __construct(private readonly FakeGateway $gateway) {}

    public function parse(): array
    {
        $this->gateway->webhookCalls[] = 'parse';

        if ($this->gateway->webhookParseFailure !== null) {
            throw $this->gateway->webhookParseFailure;
        }

        return $this->gateway->webhookPayload;
    }

    public function pipeline(): bool
    {
        $this->gateway->webhookCalls[] = 'pipeline';

        if ($this->gateway->webhookPipelineFailure !== null) {
            throw $this->gateway->webhookPipelineFailure;
        }

        return $this->gateway->webhookHandled;
    }
}
