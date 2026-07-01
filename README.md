# isapp/laravel-cashier-support

Provider-agnostic contracts for Laravel Cashier. This package gives your
application the **same developer experience as `laravel/cashier-stripe`**
(`$user->charge()`, `newSubscription()->create()`, `checkout()`, ...) while
letting you **swap the payment gateway** (Revolut, Adyen, Wise, ...) without
touching application code.

It contains **only** interfaces, DTOs, enums, exceptions, abstract models,
traits and events — **zero business logic, zero HTTP calls**. Concrete drivers
(e.g. `isapp/laravel-cashier-revolut`) implement the contracts and are drop-in
replacements for each other.

## Installation

```bash
composer require isapp/laravel-cashier-support
```

Publish the config if you need to customise it:

```bash
php artisan vendor:publish --tag=cashier-support-config
```

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
  token, ...). Returned by `checkout()`.

## Invoices

Invoices are generated **locally** from stored data — a shared feature, not a
provider call:

```php
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

## Quality

```bash
composer test      # phpunit
composer analyse   # phpstan (larastan) level 8
composer deptrac   # architecture boundary rules (deptrac)
composer format    # laravel pint
```

`deptrac` enforces the layering (DTO/Contracts/Enums stay free of Models,
Concerns and the Manager) and the "zero HTTP" rule — see `deptrac.yaml`.

## License

MIT.
