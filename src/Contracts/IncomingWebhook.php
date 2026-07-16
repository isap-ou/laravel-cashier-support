<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnexpectedWebhookEventException;
use Isapp\CashierSupport\Exceptions\WebhookVerificationException;

/**
 * One incoming webhook delivery, handed back by WebhookHandler::webhook().
 *
 * Two calls, and the ORDER between them is the point of this contract. The webhook
 * controller in this package parses, dispatches WebhookReceived, and only then
 * applies — so a driver cannot get that order wrong, because it no longer owns it.
 * It used to: isap-ou/laravel-cashier-revolut#24 was that order inverted in a driver's
 * own controller, and it hid 14 of Revolut's 22 documented event types behind a 200
 * for the life of the package. Nothing structural stopped the next driver from
 * repeating it. Now nothing has to.
 *
 * The split mirrors the reference exactly — laravel/cashier's WebhookController.php:
 * decode (:42), WebhookReceived (:45), "is there a handler for this?" (:47), handle
 * (:50), WebhookHandled (:52). parse() is the first half; pipeline() is the second,
 * and its bool IS that method_exists() check, moved behind the contract.
 *
 * Why an object rather than two methods on the provider: verification and applying
 * both need the same raw bytes and headers, and a per-delivery object takes them once
 * instead of asking the caller to pass the same pair twice and trust it did.
 */
interface IncomingWebhook
{
    /**
     * Verify this delivery is authentic, then read its body.
     *
     * The implementation MUST verify before returning. This package refuses a webhook
     * it cannot verify — a missing signing secret is InvalidConfigurationException, not
     * a shrug — and that is a deliberate departure from both references, which attach
     * their signature middleware only `if (config(...secret))` and otherwise accept
     * unsigned webhooks with no throw and no log line (laravel/cashier's
     * WebhookController.php:29, laravel/cashier-paddle's :32). Support fixes WHEN
     * verification runs — before anything is dispatched or applied — but it cannot
     * prove THAT it ran: that half is the driver's, and its own tests are the proof.
     *
     * A body that is not a JSON object — a list, a scalar, unparseable bytes — is not an
     * event, and MUST throw rather than being flattened into an array. PHP will not catch
     * that for you: json_decode('[1,2,3]', true) is an `array`, so returning it satisfies
     * the type below while handing every listener an int-keyed list where an event
     * belongs. The empty array is worse still — to a listener it is indistinguishable
     * from a real event the driver did not map.
     *
     * @return array<string, mixed> The provider's decoded body, as sent.
     *
     * @throws WebhookVerificationException When the signature cannot be verified.
     * @throws InvalidConfigurationException When the driver has no signing secret to verify against.
     * @throws UnexpectedWebhookEventException When the body cannot be read as an event at all.
     */
    public function parse(): array;

    /**
     * Apply this delivery to local state.
     *
     * **An event this driver does not map returns false. It MUST NOT throw.** That one
     * rule is what #24 cost, and it is the reason this contract exists: it replaces an
     * ordering every driver had to get right with a single sentence one method has to
     * obey. Throwing here would strand the event — the controller has already dispatched
     * WebhookReceived, so an app's listener has seen it, but a throw would turn an event
     * we merely have no opinion about into a failed delivery the gateway retries forever.
     * Bodies that are not events at all are parse()'s business, not this method's.
     *
     * @return bool True when the event was applied; false when the driver does not map it.
     *              The controller dispatches WebhookHandled only for true — announcing a
     *              webhook was handled when nothing happened is the silence #42 removed,
     *              wearing the opposite mask.
     *
     * @throws CashierException When applying failed and the delivery deserves a retry.
     */
    public function pipeline(): bool;
}
