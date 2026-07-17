<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Isapp\CashierSupport\Contracts\IncomingWebhook;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Contracts\WebhookHandler, refused.
 *
 * Refusing here is close to theoretical — Http\Controllers\WebhookController calls webhook()
 * for every delivery, so a gateway that cannot answer it has no way to learn what happened
 * to a payment. The default exists for the same reason as the rest: so that the method being
 * added to the contract was not a fatal error, and so a driver in progress still loads.
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 */
trait RefusesWebhooks
{
    public function webhook(string $content, array $headers): IncomingWebhook
    {
        throw UnsupportedOperationException::forCapability(Capability::Webhooks);
    }
}
