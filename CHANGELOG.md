# Changelog

All notable changes to `isapp/laravel-cashier-support` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> The first release, **1.0.0**. The package was never published to a consumer, so
> its pre-release history has been collapsed into this one entry rather than
> carried as a version trail that describes tags nobody ever installed.

### Added

- `Exceptions\UnexpectedWebhookEventException` — "the gateway sent an event this driver
  does not handle" is provider-agnostic, and it used to be a driver-private type thrown
  from a contract method: undeclared, and uncatchable without naming the driver.
  `Contracts\WebhookHandler` now declares what both its methods throw, and
  `ExceptionBoundaryTest` sweeps it too — the contract had escaped the sweep, which is
  precisely why the hole survived.

- `Capability::SubscriptionMetadata`, gating `SubscriptionBuilder::withMetadata()` — the
  last ungated method on the builder. A gateway with nowhere to put a metadata map used
  to accept the call and let the driver drop the data on the floor.

- **A scheduled price change now has somewhere to live.** `next_price` /
  `next_price_starts_at` on `cashier_subscriptions`, `DTO\Subscription::$pendingPrice` /
  `$pendingPriceStartsAt`, `Models\Subscription::hasPendingPriceChange()` /
  `pendingPrice()` / `pendingPriceStartsAt()`, and a new
  `Events\SubscriptionPriceChangeScheduled`.

  Where a gateway defers a plan change to the end of the billing cycle, the
  subscription stays billed on the old price — and the item row must keep naming it,
  or the record would lie about what the customer pays. That left the requested price
  in no column, no DTO field and no event: a *successful* swap was indistinguishable
  from no swap, and "you'll move to Pro on 1 Aug" could not be rendered.

  The scheduling event is deliberately not `SubscriptionUpdated`: nothing the customer
  is billed on has changed yet, and a listener provisioning entitlements on "updated"
  would grant the new plan a cycle early. `SubscriptionUpdated` still announces the
  moment the change lands.

- **A capability now gates an intent, not merely an operation.** `Capability::SubscriptionSwap`
  became `SubscriptionSwapImmediate` + `SubscriptionSwapAtPeriodEnd`, chosen by a new
  `Enums\SwapTiming` the caller passes to `swapSubscription()` (default `Immediate` —
  Stripe's and Paddle's semantics). `Capability::Checkout` became `CheckoutPrices` +
  `CheckoutAmount`, gated on the shape of a new `DTO\CheckoutRequest`
  (`forPrices()` / `forAmount()`), plus `Contracts\CheckoutSession::clientSecret()`.

  A boolean "supports swap" was true both for a gateway that swaps immediately and for
  one that defers the change to the end of the billing cycle — a difference an app cannot
  ignore and could not ask about, so it branched on the driver name instead. Same for
  checkout: one gateway takes price ids, another takes an amount, and the contract typed
  only the first, so the amount had to be smuggled through an untyped options bag and the
  driver threw its own exception when it was missing. Gating on the request's shape means
  a mis-shaped request is refused **in this package**, before any driver sees it.

- **The gateway customer identity is a first-class record.** New `cashier_customers`
  table (morphed owner + `provider` + `provider_id`), abstract `Models\Customer`, a
  `'customer'` model slot, `Billable::hasCustomerId()` / `customerId()` /
  `createOrGetCustomer()` / `cashierCustomer()`, and a driver-facing
  `Gateway\ManagesCustomerRecords` (sibling of `ManagesLocalInvoices`).

  It previously lived as a driver-named column on the app's own users table, which
  forbade two things **structurally**: a second driver needed a second column, and a
  reverse lookup by customer id — which every order webhook needs — could only ever
  search one configured class. A Team could not be billed alongside a User: its
  order webhook resolved no owner, and its invoice was silently dropped.

  `resolveOwnerByCustomerId()` is the point of the whole change: it finds the owner
  of **any** billable type.

  The **write** stays in the driver, deliberately. If `createAsCustomer()` wrote the
  row, a driver that had not registered a `'customer'` model would start throwing the
  moment it created a customer. Support ships the table, the model and the read API.
  For the same reason the read API answers "no" rather than exploding when a driver
  has registered no customer model — a driver that stores no customers is a
  legitimate driver.


- **A subscription knows the period it is paid through.** New nullable
  `current_period_start` / `current_period_end` columns, `Models\Subscription::currentPeriodStart()`
  / `currentPeriodEnd()` (Stripe's names), and trailing DTO fields. `ends_at` only
  ever said when *access* stops, and only on cancellation — so a live subscription
  could not answer "when am I next billed?", nor, after a plan change scheduled at
  cycle end, "when does the new plan start?" (the same date).

  The period is **persisted**, not fetched live. Stripe can afford a live accessor
  because the period is inline on the object it already holds; for a gateway whose
  period sits behind a separate call, that would be a round-trip per read. `NULL`
  means "unknown" — a gateway may expose no billing cycle at all, so this is data,
  not a capability, and no contract method was added.

- **`Events\SubscriptionRenewed`** — a paid billing cycle, carrying the invoice
  that settled it. A plain renewal previously fired *no* subscription event at all
  (`SubscriptionUpdated` is gated on a plan change), so an app had nothing to hang
  "extend entitlement, send receipt" on. It is a typed event rather than a
  `WebhookEvent` case because a gateway may not be able to classify a renewal at
  parse time — Revolut only says "an order completed", and that it paid for a
  cycle is learned after a refetch.

- **`Events\SubscriptionPastDue`** and `WebhookEvent::SubscriptionPastDue` — a
  failed payment is not "something changed". Dunning, grace-period warnings and
  suspension all need their own signal instead of inferring it from
  `SubscriptionUpdated`.

- **Invoices are tied to what they paid for.** New nullable `subscription_id`,
  `period_start`, `period_end` and `billing_reason` (`Enums\BillingReason`) on
  `cashier_invoices`, plus `Models\Invoice::subscription()`. A renewal invoice was
  previously unlinkable to either the subscription or the cycle — and this package
  renders these invoices to PDF, so an invoice that cannot state its service period
  is not a usable invoice.

### Changed

- **The exception boundary is stated, and true.** `CashierException` claimed that
  *every* exception thrown by the package and its drivers extends it. It never did:
  a malformed argument raises SPL's `InvalidArgumentException`, here and in the
  reference alike (`laravel/cashier`'s `Subscription::swap()`: "Please provide at
  least one price when swapping").

  The docblock now says what actually holds — a **billing** failure is catchable
  (`catch (CashierException)` gets all of them), a **malformed argument** is a
  programmer error to be fixed, not caught — and every gateway operation on every
  contract now declares what it throws. `SubscriptionUpdateFailure::invalidPrice()`
  is gone: it encoded a bad argument as an update failure, which invites an app to
  catch its own bug.

  `charge()` now enforces its own half of that boundary: a non-positive amount raises
  `InvalidArgumentException` instead of travelling to the gateway and coming back as a
  4xx — i.e. as a *billing* failure the app is invited to catch and swallow.

- **Breaking for implementors and callers of swap/checkout.** `swapSubscription()` takes
  `SwapTiming $timing` as its third argument, ahead of `$options`; `checkout()` on the
  `CheckoutOperations` contract takes a `CheckoutRequest`; `CheckoutSession` gained
  `clientSecret()`. `Billable::checkout()` still accepts a price id or an items map — that
  is the same price-shaped request — but an amount is no longer smuggled through options,
  so a gateway that checks out an amount refuses the price-shaped form (that is the point).
  Nothing was ever published, so no installation is affected.

- `quantity` on a subscription item is nullable —
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

  **The gate denies by default.** A driver that does not enumerate the capability
  cannot set a quantity at all — driver authors must add
  `Capability::SubscriptionQuantity` to `capabilities()` if their gateway really
  supports one.

### Fixed

- Declared tax rates are never silently
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

[Unreleased]: https://github.com/isap-ou/laravel-cashier-support/commits/main
