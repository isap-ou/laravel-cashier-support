# Cashier Baseline — mirrored surface of laravel/cashier-stripe

> Maintained by the `track-cashier` skill. This is the **floor** the drift detector
> diffs against. Bump `version` and refresh the manifest only via
> `track-cashier --update-baseline` (or explicit approval). Keep under version control.

version: v16.6.0
checked: 2026-07-01
source: https://packagist.org/packages/laravel/cashier (latest stable at check time)

---

> ## ⚠️ We are NOT at parity with this manifest — read before running the skill
>
> A five-way audit on **2026-07-14** compared `src/` against `vendor/laravel/cashier` and
> `vendor/laravel/cashier-paddle` method-by-method. The manifest below is a **declared
> target, not a description of the code.** Large parts of it were never implemented:
>
> | Manifest claims | Reality (2026-07-14) |
> |---|---|
> | `Subscription` model: `cancel/cancelNow/cancelAt/resume/swap/pause` | **None exist.** The model has no mutators; mutations live on `Billable` as `cancelSubscription()`, `swapSubscription()`, … (#39) |
> | `Subscription`: `incrementQuantity/decrementQuantity` | Do not exist. Quantity is write-once, on the builder (#37) |
> | `Subscription`: `pastDue()`, `recurring()` | Do not exist (#29) |
> | `Billable::subscribedToProduct()` | Does not exist (#37) |
> | `Billable::deletePaymentMethods()` | Does not exist (#37) |
> | `Billable::invoices(bool $includePending, array $parameters)` | Signature is `invoices(array $parameters)`, returns `array` not `Collection` |
> | `SubscriptionStatus`: 6 cases | Correct — but that is the **bug**: Stripe has 8. Missing `unpaid`, `incomplete_expired` → `ValueError` on read (#22) |
> | `PaymentMethodType` as an enum | It is an **interface** (`src/Contracts/`), backed by a driver-owned enum |
>
> **Consequence for this skill:** a `track-cashier` run that diffs upstream against this
> manifest will report "in parity" for surface we never built. Until the manifest is
> reconciled against real `src/` (not against `CLAUDE.md`), treat every "no drift" result on
> the rows above as a false negative.
>
> Full issue list: isap-ou/laravel-cashier-support#22-#39. Start with #28 (interface
> segregation) — it blocks most of the others.

## Manifest

The lists below are the **declared target surface** for `isapp/laravel-cashier-support`,
sourced from this package's `CLAUDE.md` reference block and `plan.md`. On the first full
`track-cashier` run, reconcile each entry against the live upstream source files listed in
`SKILL.md` §Sources and correct any drift, then persist here.

**Entries marked ❌ are declared but NOT implemented** (see the warning above).

### Billable methods (public API mirrored 1:1)

- `charge(int $amount, string $paymentMethod, array $options = [])`
- `refund(string $paymentId, array $options = [])`
- `newSubscription(string $type, string|array $prices)` → SubscriptionBuilder
- `subscription(string $type = 'default')`
- `subscribed(string $type = 'default')`
- ❌ `subscribedToProduct(string|array $products, string $type = 'default')` — not implemented (#37)
- `subscribedToPrice(string|array $prices, string $type = 'default')`
- `onTrial(string $type = 'default')`
- `onGracePeriod(string $type = 'default')`
- `checkout(array|string $items, array $options = [])`
- `createAsCustomer(array $options = [])`
- `asCustomer()`
- `defaultPaymentMethod()`
- `addPaymentMethod(string $paymentMethod)`
- ❌ `deletePaymentMethods()` — not implemented (#37)
- ⚠️ `invoices(bool $includePending = false, array $parameters = [])` — actual signature is
  `invoices(array $parameters = []): array` (no `$includePending`, returns `array` not `Collection`)
- `downloadInvoice(string $id, array $data = [])` — actual signature drops `?string $filename`

### Added by us, absent from Cashier (deliberate — the multi-gateway abstraction)

- `cancelSubscription/cancelSubscriptionNow/resumeSubscription/pauseSubscription/swapSubscription`
  on `Billable` — these REPLACE the model mutators below (#39, undecided)
- `cashierDriver()`, `cashierCustomer()`, `ensureCashierSupports(Capability)`

### SubscriptionBuilder methods

- `trialDays(int $days)`
- `trialUntil($date)`
- `create(string $paymentMethod = null, array $options = [])`
- `add()`

### Subscription (model) methods

❌ **The entire mutator list below is unimplemented.** `Models\Subscription` is a read-only
mirror; these live on `Billable` under different names instead (#39).

- ❌ `cancel()`, `cancelNow()`, `cancelAt($date)` — `cancelAt` has no equivalent at all
- ❌ `resume()`
- ❌ `swap(string|array $prices, array $options = [])`
- ❌ `pause()` — and there is no `paused_at` column to record it (#30)
- ❌ `incrementQuantity(int $count = 1)`, `decrementQuantity(int $count = 1)` (#37)
- `active()` ⚠️ — implemented, but its semantics are Cashier's **`valid()`**, not Cashier's
  `active()`: a `past_due` subscription in grace still returns `true` (#25)
- `canceled()`, `onTrial()`, `onGracePeriod()` — implemented; `ended()` is named `hasEnded()`
- ❌ `pastDue()`, `recurring()` — do not exist. Nor does **any query scope** (#29)

### Enums

- **SubscriptionStatus**: active, past_due, canceled, incomplete, trialing, paused
  ⚠️ **BUG** — Stripe emits 8: `unpaid` and `incomplete_expired` are missing, and the cast
  uses `BackedEnum::from()`, so such a row throws `ValueError` on read (#22)
- **PaymentStatus**: pending, processing, succeeded, failed, canceled, refunded
- **PaymentMethodType**: card, bank_transfer, revolut_pay, apple_pay, google_pay, sepa
- **RefundReason**: duplicate, fraudulent, requested_by_customer, other
- **Interval**: day, week, month, year
- **CheckoutMode**: payment, subscription, setup
- **Currency**: ISO 4217 (EUR, USD, GBP, PLN, CZK, ...)

### Webhook events mirrored (`Enums/WebhookEvent`)

- payment.succeeded
- payment.failed
- subscription.created
- subscription.updated
- subscription.canceled
- refund.completed

> ⚠️ The upstream Stripe `WebhookController` handles Stripe-named events
> (`customer.subscription.updated`, `invoice.payment_succeeded`, ...). On first run,
> reconcile our provider-agnostic names against the upstream handled set and record the
> mapping here.

### Events (`src/Events`)

- WebhookReceived, WebhookHandled
- SubscriptionCreated, SubscriptionUpdated, SubscriptionCanceled
- PaymentSucceeded, PaymentFailed, RefundProcessed

---

## Reconciliation log

| date | from → to | notable changes |
|---|---|---|
| 2026-07-01 | — → v16.6.0 | initial baseline (declared target from CLAUDE.md; not yet reconciled against live upstream source) |
| 2026-07-14 | v16.6.0 (unchanged) | **Reconciled the manifest against our own `src/` for the first time** — not against upstream. Finding: the manifest was aspirational. Subscription mutators, quantity mutation, `subscribedToProduct`, `deletePaymentMethods`, `pastDue`/`recurring` and all query scopes were never implemented; `active()` carries `valid()` semantics; `SubscriptionStatus` is missing 2 of Stripe's 8 states. Manifest rows annotated ❌/⚠️ accordingly. Upstream version NOT bumped — this run compared us to ourselves, so the upstream diff floor is untouched. Issues #22-#39. |
