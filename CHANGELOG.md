# Changelog

All notable changes to `isapp/laravel-cashier-support` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Macroable `CashierManager` (macros first, unknown calls still forward to the
  default driver).
- Per-driver model registry: `Cashier::useModels()` +
  `subscriptionModel()/subscriptionItemModel()/invoiceModel()` — replaces the
  single global `cashier-support.models.*` slot for multi-driver installs.
- Query-side Billable API reading local records: `subscriptions()`,
  `subscription()`, `subscribed()`, `onTrial()`, `onGracePeriod()`,
  `subscribedToPrice()`.
- `Gateway\ManagesLocalInvoices` — default local-record InvoiceOperations for
  drivers whose provider has no invoice API.
- `Capability::SubscriptionCancelNow`; `cancelSubscriptionNow()` is now gated
  by it instead of silently sharing the plain cancel capability.

### Changed

- Dropped the unused `moneyphp/money` and `ext-intl` requirements (money is
  represented as integer minor units plus the `Currency` enum).

### Added

- Provider-agnostic billing contracts mirroring the Laravel Cashier (Stripe) API.
- `GatewayProvider` contract aggregating customer, charge, subscription, invoice,
  payment method, checkout and webhook operations, plus a granular capability system.
- Spatie Laravel Data DTOs (`Customer`, `Payment`, `Subscription`, `SubscriptionItem`,
  `Invoice`, `InvoiceLine`, `PaymentMethod`, `Refund`, `WebhookPayload`).
- String-backed enums; user-facing ones (`PaymentStatus`, `SubscriptionStatus`,
  `RefundReason`) use `isap-ou/laravel-enum-helpers` for translatable labels.
- `PaymentMethodType` and `CheckoutSession` as provider-implemented contracts.
- `CashierManager` driver registry and `Cashier` facade; per-model driver
  selection via `cashierDriver()`.
- `Billable` meta-trait and operation concerns, gated by provider capabilities
  (`UnsupportedOperationException` when unsupported).
- Domain events (`WebhookReceived`, `PaymentSucceeded`, `SubscriptionCreated`, ...).
- Abstract Eloquent models and local invoice generation (`InvoiceBuilder` +
  `InvoiceRenderer` via `spatie/laravel-pdf`).
- Publishable migrations with UUID primary keys (UUIDv7 on Laravel 12+).
- `track-cashier` skill for detecting upstream `laravel/cashier-stripe` API drift.
- Tooling: PHPStan (larastan) level 8, Pint, deptrac boundary rules, and a
  Laravel 11/12/13 CI matrix.
- Release checklist (`RELEASING.md`) and a PR changelog enforcer.

[Unreleased]: https://github.com/isap-ou/laravel-cashier-support/commits/main
