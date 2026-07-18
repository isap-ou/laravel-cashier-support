# isapp/laravel-cashier-support

[![Latest Version on Packagist](https://img.shields.io/packagist/v/isapp/laravel-cashier-support.svg?style=flat-square)](https://packagist.org/packages/isapp/laravel-cashier-support)
[![Tests](https://img.shields.io/github/actions/workflow/status/isap-ou/laravel-cashier-support/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/isap-ou/laravel-cashier-support/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/isapp/laravel-cashier-support.svg?style=flat-square)](https://packagist.org/packages/isapp/laravel-cashier-support)
[![License](https://img.shields.io/packagist/l/isapp/laravel-cashier-support.svg?style=flat-square)](LICENSE)

Provider-agnostic contracts for Laravel Cashier. This package gives your
application the **same developer experience as `laravel/cashier-stripe`**
(`$user->charge()`, `newSubscription()->create()`, `checkout()`, ...) while
letting you **swap the payment gateway** (Revolut, Adyen, Wise, ...) without
touching application code.

It is **mostly** interfaces, DTOs, enums, exceptions, abstract models, traits
and events, with a thin layer of provider-agnostic behaviour — invoice assembly
(`src/Invoice/`) and local customer/invoice persistence (`src/Gateway/`). It makes
**zero *outbound* HTTP calls**: it is only ever *called* by a gateway (through the
webhook controller), never the other way round. Concrete drivers
(e.g. `isapp/laravel-cashier-revolut`) implement the contracts and are drop-in
replacements for each other.

> **Status — pre-release.** No version is tagged on Packagist yet. Until the
> first `v1.0.0` is published, require it from the VCS repository (add to your
> app's `composer.json`):
>
> ```json
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/isap-ou/laravel-cashier-support" }
> ]
> ```

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [How it works](#how-it-works)
- [Making a model billable](#making-a-model-billable)
- [Capabilities](#capabilities)
- [Strong Customer Authentication (SCA / 3DS)](#strong-customer-authentication-sca--3ds)
- [Provider-defined types](#provider-defined-types)
- [Invoices](#invoices)
- [Events](#events)
- [Localized enum labels](#localized-enum-labels)
- [Keeping in sync with Stripe Cashier](#keeping-in-sync-with-stripe-cashier)
- [Extending](#extending)
- [Quality](#quality)
- [Changelog & releases](#changelog--releases)
- [License](#license)

## Requirements

PHP `^8.2` and Laravel **11, 12 or 13** (Laravel **12+ recommended**).

> **Laravel 11 is EOL.** It is supported for compatibility, but is past its
> security-support window — all `11.x` releases are flagged by Composer's
> advisory audit, so installing on Laravel 11 requires allowing insecure
> packages. Prefer Laravel 12 or 13 for production.

> **UUID note:** the local models use `HasUuids`. On Laravel 12+ that yields
> UUIDv7 primary keys; on Laravel 11 it falls back to ordered UUIDs (v4), which
> is Laravel 11's `HasUuids` default. Both are valid UUIDs — only the version
> differs.

## Installation

```bash
composer require isapp/laravel-cashier-support
```

Publish the config if you need to customise it:

```bash
php artisan vendor:publish --tag=cashier-support-config
```

`config/cashier-support.php` exposes the default `driver`, the default
`currency`, the concrete `models` bindings, and `invoices` (view, paper size,
seller details).

The abstract `Subscription`, `SubscriptionItem` and `Invoice` models are
optional local records. If you want to persist them, publish and run the
provider-agnostic migrations (tables `cashier_subscriptions`,
`cashier_subscription_items`, `cashier_invoices`):

```bash
php artisan vendor:publish --tag=cashier-support-migrations
php artisan migrate
```

## How it works

```
App  ──uses──►  Billable trait  ──delegates──►  CashierManager (driver registry)
                                                       │
                                     resolves the configured GatewayProvider driver
                                                       │
                          isapp/laravel-cashier-revolut, -adyen, -wise, ...
```

A concrete provider package registers itself as a driver:

```php
use Isapp\CashierSupport\Facades\Cashier;

Cashier::extend('revolut', fn ($app) => $app->make(RevolutGateway::class));
```

Select the default driver in `config/cashier-support.php` (or via the
`CASHIER_DRIVER` env var):

```php
'default' => env('CASHIER_DRIVER', 'revolut'),
```

## Making a model billable

```php
use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Billable;

class User extends Model
{
    use Billable;
}
```

```php
$user->charge(1500, 'pm_visa', ['currency' => 'eur']);
$user->refund('pay_123');
$user->newSubscription('default', 'price_monthly')->trialDays(14)->create();
$user->subscription('default')->cancel();
$user->checkout(CheckoutRequest::forPrices('price_monthly', successUrl: '...', cancelUrl: '...'));
$user->addPaymentMethod('pm_visa');
$user->invoices();
```

Money is always **integer minor units** (cents) plus a `Currency` enum.

### Per-model driver

Override `cashierDriver()` to bill a model through a non-default gateway:

```php
class Vendor extends Model
{
    use Billable;

    public function cashierDriver(): ?string
    {
        return 'adyen';
    }
}
```

## Capabilities

Providers declare which features they support. `Cashier::provider()` returns a guard that checks
the capability before every operation; unsupported operations throw `UnsupportedOperationException`
— there are **no local workarounds**.

```php
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;

if (Cashier::supports(Capability::SubscriptionPauseImmediate)) {
    $user->subscription('default')->pause();
}
```

### Capabilities gate an intent, not just an operation

Where two gateways do the "same" operation with semantics an app cannot ignore,
the capability splits, and the caller states which one it means. It never
inspects the driver name.

**Swap timing.** Stripe and Paddle change the plan immediately; Revolut only ever
schedules it for the end of the billing cycle. So the caller says which it needs,
and a gateway that cannot honour it says so:

```php
use Isapp\CashierSupport\Enums\SwapTiming;

// Default. Throws UnsupportedOperationException on a defer-only gateway
// rather than quietly giving you a change that lands next month.
$user->subscription('default')->swap('price_yearly');

$user->subscription('default')->swap('price_yearly', SwapTiming::AtPeriodEnd);
```

A deferred change needs somewhere to live. The subscription keeps being billed on
the current price — `items()` names it, and `subscribedToPrice()` keeps answering
for it, because that is what the customer is paying — while the price it will move
to is recorded separately:

```php
$subscription = $user->subscription('default');

if ($subscription->hasPendingPriceChange()) {
    // "You'll move to Pro on 1 Aug"
    $subscription->pendingPrice();          // 'price_yearly'
    $subscription->pendingPriceStartsAt();  // CarbonImmutable|null — null means the
                                            // gateway did not say when
}
```

`SubscriptionPriceChangeScheduled` fires when the change is scheduled, and
`SubscriptionUpdated` when it actually lands — so a listener that provisions
entitlements does not grant the new plan a cycle early.

**Pause is immediate-only.** Unlike swap, pause has no timing. Every gateway that
pauses does it now — Stripe's `pause_collection` pauses immediately — and
pause-at-period-end was Paddle-only with no driver behind it, so it was removed
(#72). A gateway that cannot pause says so:

```php
// Pause now. Throws UnsupportedOperationException on a gateway that cannot pause.
$user->subscription('default')->pause();

// Optionally carry an auto-resume date, where the gateway accepts one
// (Stripe's pause_collection.resumes_at).
$user->subscription('default')->pause($resumesAt);
```

The paused state has its own columns, independent of how the pause was requested.
`paused_at` is the instant the pause takes effect, so tense tells a pause in force
apart from one a gateway reports as not yet effective:

```php
$subscription = $user->subscription('default');

$subscription->onPausedGracePeriod();  // paused_at in the future — not yet effective, still serving
$subscription->paused();               // paused_at in the past — the pause is in force
```

**Checkout shape.** Some gateways check out a catalogue of prices, others an
ad-hoc amount. `CheckoutRequest` names both, and the gate keys on the shape — so
a mis-shaped request throws here, before any driver sees it:

```php
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Money\Currency;

$user->checkout(CheckoutRequest::forPrices(['price_monthly' => 1], successUrl: '...'));
$user->checkout(CheckoutRequest::forAmount(1500, new Currency('EUR'), 'One coffee'));
```

A bare price id or an items map still works — it is a price-shaped request.

## Exceptions — what to catch

A **billing** failure is a fact about the world: the card was declined, the gateway
cannot pause, the customer does not exist, the API is down. The app cannot prevent
it, so it must be able to catch it — every one of them extends `CashierException`,
in this package and in every driver.

A **malformed argument** — swapping to no price, checking out a negative amount —
is a programmer error. It raises SPL's `InvalidArgumentException`, exactly as
Stripe Cashier does, and is meant to be fixed rather than caught.

```php
try {
    $user->subscription('default')->swap('price_yearly');
} catch (CashierException $e) {
    // Declined, unsupported, gateway down — recoverable, show the user something.
}
// An InvalidArgumentException here means the call itself is wrong. Do not catch it.
```

## Strong Customer Authentication (SCA / 3DS)

A charge does not always complete in one step: under SCA the customer must authenticate
(3DS) before the payment can settle. When the gateway answers with such a state, `charge()`
throws an `IncompletePaymentException` carrying what the frontend needs to **resume** the
payment — the payment id, its status, and the `clientSecret`:

```php
try {
    $user->charge(1000, 'pm_1');
} catch (IncompletePaymentException $e) {
    // Hand $e->clientSecret to your client-side SDK to complete authentication.
    return response()->json([
        'payment_id'    => $e->paymentId,
        'status'        => $e->status?->value,   // e.g. requires_action
        'client_secret' => $e->clientSecret,
    ]);
}
```

`DTO\Payment` also exposes the state directly — `requiresAction()`, `requiresConfirmation()`,
`requiresPaymentMethod()` — and `Payment::$clientSecret` (nullable; provider-neutral — Stripe
`client_secret`, Adyen `sessionData`, Revolut order token, ... — `null` when a gateway has no
such concept, and filled by the driver).

What support does **not** ship is the completion UI. The hosted confirmation page and the
client-side authentication step are inherently provider-specific (each gateway has its own JS
SDK and publishable-key model), so they are the **driver's or application's** responsibility.
This package's job is to make the SCA flow *expressible* — the data surface above — not to
drive it.

## Provider-defined types

Some shapes are provider-specific and are expressed as **contracts**, so each
driver returns its own implementation:

- `Contracts\PaymentMethodType` — a string-backed enum of the provider's payment
  method types (`card`, `sepa`, `revolut_pay`, ...). `DTO\PaymentMethod::$type`
  type-hints this contract.
- `Contracts\CheckoutSession` — a hosted checkout session (redirect URL, widget
  token, ...) returned by `checkout()`. A driver may also implement
  `Illuminate\Contracts\Support\Responsable`, so you can `return $user->checkout(...)`
  straight from a controller.

## Invoices

Invoice **data** is assembled **locally** from stored records — a shared feature, not a
provider call — with `Invoice\InvoiceBuilder`:

```php
use Isapp\CashierSupport\Invoice\InvoiceBuilder;
use Money\Currency;

$invoice = InvoiceBuilder::make()
    ->id('in_1')->currency(new Currency('EUR'))
    ->addLine('Pro plan', 1000)
    ->build();
```

**Rendering is the driver's, not this package's** — support ships no PDF engine. A gateway
that renders local invoices implements `Contracts\RendersInvoices` and returns its own
`Contracts\InvoiceRenderer`, which turns an `Invoice` DTO into bytes. Support handles delivery
off those bytes — a streamed download or a saved file:

```php
return $user->downloadInvoice('in_1');                          // streamed PDF response
$path = $user->storeInvoice('in_1');                            // written to the default disk, returns the path
$path = $user->storeInvoice('in_1', disk: 's3', path: 'invoices/in_1.pdf');
```

A gateway that does not implement `Contracts\RendersInvoices` answers both with
`UnsupportedOperationException`.

## Events

`WebhookReceived`, `WebhookHandled`, `SubscriptionCreated`,
`SubscriptionUpdated`, `SubscriptionCanceled`, `SubscriptionRenewed`,
`SubscriptionPastDue`, `SubscriptionPriceChangeScheduled`, `PaymentSucceeded`,
`PaymentFailed`, `RefundProcessed`. Dispatch them from your driver via
`event(...)`.

`SubscriptionPriceChangeScheduled` and `SubscriptionUpdated` are not
interchangeable: the first says a change **will** happen at the end of the cycle,
the second that it **has**. A listener that provisions entitlements wants the
second, or it grants the new plan a cycle early.

## Localized enum labels

User-facing enums (`PaymentStatus`, `SubscriptionStatus`, `RefundReason`) use
`isap-ou/laravel-enum-helpers` for translatable labels:

```php
PaymentStatus::Succeeded->getLabel(); // "Succeeded" (translatable)
```

Translations live under the `cashier-support` namespace; publish them with
`--tag=cashier-support-lang`.

## Keeping in sync with Stripe Cashier

The `track-cashier` skill (`.claude/skills/track-cashier`) detects API changes
in `laravel/cashier-stripe` since a pinned baseline and maps them onto this
package's contracts, so parity can be maintained on a cadence.

## Extending

**Custom or overridden driver.** Drivers are plain `Cashier::extend()`
registrations. Your application's service providers boot after the package
ones, so re-registering a name replaces the driver — subclass a concrete
gateway (or implement `GatewayProvider` from scratch) and register it:

```php
// AppServiceProvider::boot()
Cashier::extend('revolut', fn ($app) => $app->make(MyRevolutGateway::class));

// or side-by-side under its own name, selected per model via cashierDriver()
Cashier::extend('revolut-b2b', fn ($app) => $app->make(B2bRevolutGateway::class));
```

**Macros.** `CashierManager` is macroable, so you can attach helpers to the
`Cashier` facade without subclassing. Non-macro calls still forward to the
default driver:

```php
Cashier::macro('chargeInCents', function (Model $billable, int $amount): Payment {
    return $this->provider()->charge($billable, $amount, 'pm_default');
});
```

Methods a custom gateway exposes beyond the `GatewayProvider` contract are not
visible through the `Billable` trait — call them via `Cashier::provider()` (or
`Cashier::driver('name')`) and narrow the type.

## Quality

```bash
composer test      # phpunit
composer analyse   # phpstan (larastan) level 8
composer deptrac   # architecture boundary rules (deptrac)
composer format    # laravel pint
```

`deptrac` enforces the layering (DTO/Contracts/Enums stay free of Models,
Concerns and the Manager) and the "zero HTTP" rule — see `deptrac.yaml`.

## Changelog & releases

See [CHANGELOG.md](CHANGELOG.md) for what changed between versions, and
[RELEASING.md](RELEASING.md) for the release process. This package follows
[Semantic Versioning](https://semver.org).

## License

Released under the MIT License. See [LICENSE](LICENSE).
