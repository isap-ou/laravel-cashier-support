# isapp/laravel-cashier-support

## Purpose

Provider-agnostic contracts for Laravel Cashier.
**Primary reference — `laravel/cashier-stripe` v16 (Laravel 12).** Fallback — `mollie/laravel-cashier-mollie` v2.

This package contains ONLY: interfaces, DTOs, enums, exceptions, abstract models, traits, events.
**Zero business logic. Zero HTTP calls.**

Concrete implementations (`isapp/laravel-cashier-revolut`, future `-adyen`, `-wise`) are drop-in replacements for each other.

## Reference — laravel/cashier-stripe

Method names are 1:1 with Stripe Cashier:

```php
// Billable trait on User model
$user->charge(1000, 'pm_xxx', ['currency' => 'eur']);
$user->refund('pi_xxx');
$user->newSubscription('default', 'price_monthly')->trialDays(14)->create();
$user->subscription('default')->cancel();
$user->subscription('default')->resume();
$user->subscription('default')->swap('price_yearly');
$user->subscribed('default');
$user->subscribedToProduct('pro_basic');
$user->subscribedToPrice('price_monthly');
$user->onTrial('default');
$user->onGracePeriod('default');
$user->checkout(['price_xxx' => 1], ['success_url' => '...', 'cancel_url' => '...']);
$user->createAsCustomer(['name' => '...', 'email' => '...']);
$user->asCustomer();
$user->defaultPaymentMethod();
$user->addPaymentMethod('pm_xxx');
$user->deletePaymentMethods();
$user->invoices();
$user->downloadInvoice('inv_xxx');
```

## Architecture

```
src/
├── Contracts/           # Interfaces
│   ├── GatewayProvider.php          # capabilities(), supports(Capability)
│   ├── CustomerOperations.php
│   ├── ChargeOperations.php
│   ├── SubscriptionOperations.php
│   ├── SubscriptionBuilder.php
│   ├── InvoiceOperations.php
│   ├── PaymentMethodOperations.php
│   ├── CheckoutOperations.php
│   └── WebhookHandler.php
├── DTO/                 # Spatie Laravel Data classes
│   ├── Customer.php, Payment.php, Subscription.php, SubscriptionItem.php
│   ├── Invoice.php, InvoiceLine.php, PaymentMethod.php
│   ├── Refund.php, CheckoutSession.php, WebhookPayload.php
├── Enums/               # String-backed BackedEnum
│   ├── PaymentStatus.php, SubscriptionStatus.php, Currency.php
│   ├── PaymentMethodType.php, RefundReason.php, WebhookEvent.php
│   ├── Interval.php, CheckoutMode.php
│   └── Capability.php               # Granular feature flags per provider
├── Exceptions/          # Hierarchy from CashierException
│   ├── CashierException.php, PaymentFailedException.php
│   ├── IncompletePaymentException.php, CustomerNotFoundException.php
│   ├── InvalidConfigurationException.php, WebhookVerificationException.php
│   ├── SubscriptionUpdateFailure.php
│   └── UnsupportedOperationException.php  # Thrown for unsupported capabilities
├── Concerns/            # Traits for Billable model
│   ├── ManagesCustomer.php, ManagesSubscriptions.php
│   ├── ManagesPaymentMethods.php, ManagesInvoices.php
│   ├── PerformsCharges.php, HandlesCheckout.php, HandlesTaxes.php
├── Events/              # Laravel events
│   ├── WebhookReceived.php, WebhookHandled.php
│   ├── SubscriptionCreated/Updated/Canceled.php
│   ├── PaymentSucceeded/Failed.php, RefundProcessed.php
├── Models/              # Abstract Eloquent
│   ├── Subscription.php, SubscriptionItem.php
│   └── Invoice.php              # Local invoice model (provider-independent)
├── Invoice/                     # Invoice generation (shared, not provider-dependent)
│   ├── InvoiceBuilder.php       # Build invoice from local payment/subscription data
│   └── InvoiceRenderer.php      # Render to PDF (dompdf/spatie-pdf)
├── Billable.php         # Meta-trait, includes all Concerns
├── Cashier.php          # Static config + ensureSupports(), supports()
└── CashierSupportServiceProvider.php
```

## Rules

- `declare(strict_types=1)` everywhere
- DTOs — extend `Spatie\LaravelData\Data` (`spatie/laravel-data`)
- Enums — `string`-backed, `snake_case` values
- Money — `int` (cents) + `Currency` enum + `moneyphp/money`
- Method names strictly from Stripe Cashier
- PSR-12 (Pint), PHPStan level 8+ (Larastan)
- Concerns delegate through `CashierManager` (`Cashier::provider()`), never `app(GatewayProvider::class)`
- Concerns call `Cashier::ensureSupports(Capability)` before delegating
- No custom workarounds for unsupported features — throw `UnsupportedOperationException`

## Capability system

Providers declare what they support. Unsupported operations throw `UnsupportedOperationException`.

```php
enum Capability: string {
    case Charges = 'charges';
    case Refunds = 'refunds';
    case Customers = 'customers';
    case Subscriptions = 'subscriptions';
    case SubscriptionPause = 'subscription.pause';
    case SubscriptionResume = 'subscription.resume';
    case SubscriptionSwap = 'subscription.swap';
    case SubscriptionTrials = 'subscription.trials';
    case PaymentMethodsAdd = 'payment_methods.add';
    case PaymentMethodsList = 'payment_methods.list';
    case PaymentMethodsDelete = 'payment_methods.delete';
    case Checkout = 'checkout';
    case Invoices = 'invoices';
    case Taxes = 'taxes';
    case Webhooks = 'webhooks';
    case SubscriptionCancelNow = 'subscription.cancel_now';
}
```

```php
// GatewayProvider contract
interface GatewayProvider {
    /** @return array<Capability> */
    public function capabilities(): array;
    public function supports(Capability $capability): bool;
}

// Concern checks before delegating, then resolves the driver via CashierManager
trait ManagesSubscriptions {
    public function newSubscription(string $type, string $price): SubscriptionBuilder {
        $this->ensureCashierSupports(Capability::Subscriptions);
        return $this->cashierProvider()->newSubscription($this, $type, $price);
    }
}

// App-level check
if (Cashier::supports(Capability::SubscriptionPause)) {
    $user->subscription('default')->pause();
}
```

## How a provider connects

```php
// In the ServiceProvider of a concrete package (cashier-revolut)
Cashier::extend('revolut', fn ($app) => $app->make(RevolutGateway::class));
Cashier::useModels('revolut', ['subscription' => RevolutSubscription::class /* ... */]);
```
