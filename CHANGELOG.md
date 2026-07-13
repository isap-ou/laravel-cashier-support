# Changelog

All notable changes to `isapp/laravel-cashier-support` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> Targets **2.0.0**. The changes below break implementers and readers, so they do
> not belong in a minor.

### Upgrading

- **Republish and run the migrations.** This package *publishes* its migrations
  (they are copied into the app, not loaded from the vendor directory), so a
  `composer update` alone leaves the schema behind:

  ```bash
  php artisan vendor:publish --tag=cashier-support-migrations
  php artisan migrate
  ```

  Skipping this leaves `cashier_subscription_items.quantity` `NOT NULL` while a
  2.0 driver writes `null` to it — every subscription write then fails with an
  integrity-constraint violation.

### Changed

- **Breaking for readers:** `quantity` on a subscription item is now nullable —
  `DTO\SubscriptionItem::$quantity` is `?int` (default `null`, was `int` default
  `1`), and a new migration makes the column nullable. `NULL` means **"unknown /
  not applicable"** — never zero, never one. Code typed `int $q = $item->quantity`
  must widen; the default silently changes from `1` to `null`.

  Not every gateway has a per-subscription quantity. Revolut's, for one, lives on
  the *plan variation* and is fixed when the plan is created. `NOT NULL` forced
  its driver to either invent a value — billing a five-seat plan as one seat — or
  refuse to write the item row at all, which left `subscribedToPrice()` false
  forever for any subscription the builder had not created. Nullable is what lets
  a driver record the truth.

  Rows written before the migration keep their value, so a stored `1` can no
  longer be told apart from a defaulted one.

- `Capability::SubscriptionQuantity` added, and `SubscriptionBuilder::quantity()`
  is gated on it: a provider that has no quantity concept now throws
  `UnsupportedOperationException` instead of silently accepting a number it
  cannot honour. The interface method stays — removing it would break every
  caller that type-hints the contract.

  **The gate denies by default.** Any driver that does not enumerate the new
  capability — every driver written against 1.x — loses a `quantity()` call that
  used to work. Driver authors must add `Capability::SubscriptionQuantity` to
  `capabilities()` if their gateway really supports it. This is the second reason
  the release is a major.

### Fixed

- **Breaking behaviour, deliberately:** declared tax rates are no longer silently
  discarded. Of the 16 capabilities, `Taxes` and `SubscriptionTrials` were the
  only two the package never gated — so an app that overrode `taxRates()` on a
  provider without tax support got **silence**, and its configuration was thrown
  away. Every other unsupported operation throws; these two now do too.
  - `newSubscription()` and `swapSubscription()` throw
    `UnsupportedOperationException` when the billable declares tax rates and the
    provider does not declare `Capability::Taxes`. An app in that position was
    already broken — it simply did not know it. Those two are the consumption
    points, following Stripe Cashier, which reads `taxRates()`/`priceTaxRates()`
    only when building or swapping a subscription: a one-off `charge()` and a
    `checkout()` never read them, so guarding those would turn a supported,
    tax-free operation into an outage.
  - `trialDays()` / `trialUntil()` throw when the provider does not declare
    `Capability::SubscriptionTrials`. The check lives in a new
    `Builders\GuardedSubscriptionBuilder`, which `newSubscription()` wraps around
    the provider's builder, so a driver cannot reopen the hole by forgetting to
    guard. The return type is still the `SubscriptionBuilder` contract.

  Tax rates remain a Stripe-family extension point and are **not** promoted into
  the portable core: a Stripe tax-rate id means nothing to a gateway that models
  tax as a percentage, and nothing at all to a Merchant-of-Record gateway. No
  contract method was added.

  Note the boundary: gating lives on the `Billable` surface, as all capability
  gating already did. Calling a `GatewayProvider` directly is a driver-level
  contract and is not gated.

## [1.1.0] - 2026-07-02

### Changed

- **Breaking (accepted pre-adoption, no external consumers yet):** the
  subscription type column and DTO field are renamed `name` → `type` to match
  Stripe Cashier v15+; `(provider, provider_id)` indexes on subscriptions and
  invoices are now **unique** (concurrency-safe webhook writes);
  `cashier_subscription_items` gains a `provider` column so the inverse
  relation resolves per driver.
- `active()` now includes canceled subscriptions within their paid-through
  grace period (Stripe semantics) — a canceling customer keeps access until
  `ends_at`.
- `subscriptions()` is scoped by the model's driver (`provider` column), so
  records written by another gateway never leak between drivers.
- The `cashier-support.models.*` config now only overrides models for the
  default driver; other drivers must register via `Cashier::useModels()`
  (clear exception otherwise).
- `onTrial()` no longer trusts a stale `Trialing` status once `trial_ends_at`
  is past; `subscribed()`/`onTrial()` accept an optional `$price` argument;
  `subscription()` reads the eager-loaded relation when available.
- Unknown drivers raised through `provider()` are wrapped in
  `InvalidConfigurationException` (the `CashierException` hierarchy).

### Fixed

- `Gateway\ManagesLocalInvoices`: uuid-safe invoice lookup on PostgreSQL,
  `limit` list parameter, lazily resolved renderer, sanitized download
  filename, `NotFoundHttpException` instead of `abort()`.
- Integer-only money formatting in the invoice Blade view.
- The changelog CI enforcer diffs against the merge base (no false passes).

## [1.0.0] - 2026-07-01

### Added

- Provider-agnostic billing contracts mirroring the Laravel Cashier (Stripe) API.
- `GatewayProvider` contract aggregating customer, charge, subscription, invoice,
  payment method, checkout and webhook operations, plus a granular capability
  system (`UnsupportedOperationException` when unsupported, incl.
  `Capability::SubscriptionCancelNow` gating `cancelSubscriptionNow()`).
- Spatie Laravel Data DTOs (`Customer`, `Payment`, `Subscription`, `SubscriptionItem`,
  `Invoice`, `InvoiceLine`, `PaymentMethod`, `Refund`, `WebhookPayload`).
- String-backed enums; user-facing ones (`PaymentStatus`, `SubscriptionStatus`,
  `RefundReason`) use `isap-ou/laravel-enum-helpers` for translatable labels.
- `PaymentMethodType` and `CheckoutSession` as provider-implemented contracts.
- Macroable `CashierManager` driver registry and `Cashier` facade; per-model
  driver selection via `cashierDriver()`; per-driver model registry
  (`Cashier::useModels()` + `subscriptionModel()/subscriptionItemModel()/invoiceModel()`).
- `Billable` meta-trait and operation concerns, gated by provider capabilities,
  plus the query-side API over local records: `subscriptions()`, `subscription()`,
  `subscribed()`, `onTrial()`, `onGracePeriod()`, `subscribedToPrice()`.
- `Gateway\ManagesLocalInvoices` — default local-record InvoiceOperations for
  drivers whose provider has no invoice API.
- Domain events (`WebhookReceived`, `PaymentSucceeded`, `SubscriptionCreated`, ...).
- Abstract Eloquent models and local invoice generation (`InvoiceBuilder` +
  `InvoiceRenderer` via `spatie/laravel-pdf`).
- Publishable migrations with UUID primary keys (UUIDv7 on Laravel 12+).
- `track-cashier` skill for detecting upstream `laravel/cashier-stripe` API drift.
- Tooling: PHPStan (larastan) level 8, Pint, deptrac boundary rules, a Laravel
  11/12/13 CI matrix, release checklist (`RELEASING.md`) and a PR changelog enforcer.

[Unreleased]: https://github.com/isap-ou/laravel-cashier-support/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/isap-ou/laravel-cashier-support/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/isap-ou/laravel-cashier-support/releases/tag/v1.0.0
