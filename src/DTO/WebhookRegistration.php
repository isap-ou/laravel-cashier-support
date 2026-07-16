<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Spatie\LaravelData\Data;

/**
 * The result of registering a webhook endpoint at a gateway.
 *
 * $secret is nullable because the two references disagree about it and the difference
 * is real, not an oversight: Stripe's own command prints no secret and tells the
 * operator to "Retrieve the webhook secret in your Stripe dashboard"
 * (vendor/laravel/cashier/src/Console/WebhookCommand.php), while Revolut returns a
 * signing_secret in the creation response and never again.
 *
 * So null means exactly one thing: **this gateway does not hand the secret back
 * through its API — go and read it from the dashboard.** It does NOT mean something
 * went wrong. A driver that expects a secret and does not get one throws instead:
 * the endpoint now exists at the gateway, so "quietly returned null" would leave the
 * operator with a live webhook, no secret, and no idea either had happened.
 *
 * Why not a plain string: a Stripe-shaped driver would have to return '', and an empty
 * string that means "there is no such thing here" is the sentinel that made
 * DTO\WebhookPayload unable to express an unmapped event (#42). Once was enough.
 */
class WebhookRegistration extends Data
{
    /**
     * @param  string  $id  The endpoint's id at the gateway, so an operator can find it again.
     * @param  string|null  $secret  The signing secret, when the gateway returns one at all.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $secret = null,
    ) {}
}
