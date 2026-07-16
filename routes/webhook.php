<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Isapp\CashierSupport\Http\Controllers\WebhookController;

/*
 * One route for every driver. {provider} is appended here rather than being part of the
 * configured value: it is how the controller knows which driver to ask, it is not
 * optional, and a full path in config could lose it — which registers a perfectly valid
 * route that then 500s on every delivery, because there is no parameter to bind. Stripe
 * splits it the same way: a configurable prefix, a hardcoded segment.
 *
 * The name is what `php artisan cashier:webhook` resolves the URL from, so the command
 * cannot drift from wherever this is actually mounted. That drift is not hypothetical:
 * the driver's own command used to build its URL from a config key, and a key that stops
 * matching the route registers a webhook the gateway gets a 404 from on every delivery —
 * silently.
 *
 * The methods are configured rather than hardcoded to POST. Both references hardcode it,
 * but each serves one gateway — two gateways using POST is not a rule about gateways. A
 * driver used to own its route and could say otherwise; once this package took the route,
 * the only thing standing between a future gateway and "unreachable" is this key.
 */
Route::match(
    (array) config('cashier-support.webhook.methods', ['POST']),
    trim((string) config('cashier-support.webhook.prefix', 'webhook/cashier'), '/').'/{provider}',
    WebhookController::class
)
    ->middleware(config('cashier-support.webhook.middleware', ['throttle:60,1']))
    ->name('cashier.webhook');
