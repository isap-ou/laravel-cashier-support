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

It contains **only** interfaces, DTOs, enums, exceptions, abstract models,
traits and events — **zero business logic, zero HTTP calls**. Concrete drivers
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
$user->cancelSubscription('default');
$user->checkout('price_monthly', ['success_url' => '...', 'cancel_url' => '...']);
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

Providers declare which features they support. Concerns check the capability
before delegating; unsupported operations throw `UnsupportedOperationException`
— there are **no local workarounds**.

```php
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Facades\Cashier;

if (Cashier::supports(Capability::SubscriptionPause)) {
    $user->pauseSubscription('default');
}
```

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

Invoices are generated **locally** from stored data — a shared feature, not a
provider call:

```php
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Invoice\InvoiceBuilder;
use Isapp\CashierSupport\Invoice\InvoiceRenderer;

$invoice = InvoiceBuilder::make()
    ->id('in_1')->currency(Currency::EUR)
    ->addLine('Pro plan', 1000)
    ->build();

return app(InvoiceRenderer::class)->render($invoice)->download('invoice.pdf');
```

Rendering uses `spatie/laravel-pdf`; the PDF engine is your application's choice.

## Events

`WebhookReceived`, `WebhookHandled`, `SubscriptionCreated`,
`SubscriptionUpdated`, `SubscriptionCanceled`, `PaymentSucceeded`,
`PaymentFailed`, `RefundProcessed`. Dispatch them from your driver via
`event(...)`.

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
