# Cashier Baseline — mirrored surface of laravel/cashier-stripe

> Maintained by the `track-cashier` skill. This is the **floor** the drift detector
> diffs against. Bump `version` and refresh the manifest only via
> `track-cashier --update-baseline` (or explicit approval). Keep under version control.

version: v16.6.0
checked: 2026-07-01
source: https://packagist.org/packages/laravel/cashier (latest stable at check time)

---

## Manifest

The lists below are the **declared target surface** for `isapp/laravel-cashier-support`,
sourced from this package's `CLAUDE.md` reference block and `plan.md`. On the first full
`track-cashier` run, reconcile each entry against the live upstream source files listed in
`SKILL.md` §Sources and correct any drift, then persist here.

### Billable methods (public API mirrored 1:1)

- `charge(int $amount, string $paymentMethod, array $options = [])`
- `refund(string $paymentId, array $options = [])`
- `newSubscription(string $type, string|array $prices)` → SubscriptionBuilder
- `subscription(string $type = 'default')`
- `subscribed(string $type = 'default')`
- `subscribedToProduct(string|array $products, string $type = 'default')`
- `subscribedToPrice(string|array $prices, string $type = 'default')`
- `onTrial(string $type = 'default')`
- `onGracePeriod(string $type = 'default')`
- `checkout(array|string $items, array $options = [])`
- `createAsCustomer(array $options = [])`
- `asCustomer()`
- `defaultPaymentMethod()`
- `addPaymentMethod(string $paymentMethod)`
- `deletePaymentMethods()`
- `invoices(bool $includePending = false, array $parameters = [])`
- `downloadInvoice(string $id, array $data = [])`

### SubscriptionBuilder methods

- `trialDays(int $days)`
- `trialUntil($date)`
- `create(string $paymentMethod = null, array $options = [])`
- `add()`

### Subscription (model) methods

- `cancel()`, `cancelNow()`, `cancelAt($date)`
- `resume()`
- `swap(string|array $prices, array $options = [])`
- `pause()` *(provider-gated via Capability::SubscriptionPause)*
- `incrementQuantity(int $count = 1)`, `decrementQuantity(int $count = 1)`
- `active()`, `canceled()`, `onTrial()`, `onGracePeriod()`, `pastDue()`, `recurring()`

### Enums

- **SubscriptionStatus**: active, past_due, canceled, incomplete, trialing, paused
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
