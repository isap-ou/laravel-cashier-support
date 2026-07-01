# Changelog

All notable changes to `isapp/laravel-cashier-support` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
