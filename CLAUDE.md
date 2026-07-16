# isapp/laravel-cashier-support

## Purpose

Provider-agnostic contracts for Laravel Cashier.
**Primary reference — `laravel/cashier-stripe` v16 (Laravel 12).** Second opinion —
`laravel/cashier-paddle`: where Stripe and Paddle agree, that is the shape of the
abstraction; where they differ, the difference is what a `Capability` is for.
`mollie/laravel-cashier-mollie` is a last resort only — it builds its own local
subscription engine, which the smart-stub rule forbids, so it is not a design authority.

This package contains mostly: interfaces, DTOs, enums, exceptions, abstract models, traits, events.
**Zero HTTP calls** (enforced by `deptrac.yaml`).

It is *not* literally zero business logic — `src/Invoice/` (PDF rendering, total summation) and
`src/Gateway/` (Eloquent reads/writes, HTTP responses) hold real behaviour. Do not repeat the
"zero business logic" claim; it was false and it misled agents into the wrong file. See #38.

Concrete implementations (`isapp/laravel-cashier-revolut`, future `-adyen`, `-wise`) are drop-in replacements for each other.

## Reference — laravel/cashier-stripe

Method names follow Stripe Cashier **where they exist**. They do not all exist — read
"Known divergences" below before assuming a Cashier method is here.

This is the API as it actually is today (verified against `src/`, 2026-07-14):

```php
// Billable trait on User model
$user->charge(1000, 'pm_xxx', ['currency' => 'eur']);
$user->refund('pi_xxx');
$user->newSubscription('default', 'price_monthly')->trialDays(14)->create();
$user->subscribed('default');
$user->subscribedToPrice('price_monthly');
$user->onTrial('default');
$user->onGracePeriod('default');
$user->checkout(['price_xxx' => 1], ['success_url' => '...', 'cancel_url' => '...']);
$user->createAsCustomer(['name' => '...', 'email' => '...']);
$user->asCustomer();
$user->defaultPaymentMethod();
$user->addPaymentMethod('pm_xxx');
$user->invoices();
$user->downloadInvoice('inv_xxx');

// Subscription MUTATIONS live on Billable, NOT on the model — unlike Cashier.
// `$user->subscription('default')` returns a read-only model with no mutators.
$user->cancelSubscription('default');
$user->cancelSubscriptionNow('default');
$user->resumeSubscription('default');
$user->pauseSubscription('default');
$user->swapSubscription('default', 'price_yearly', SwapTiming::AtPeriodEnd);
```

**Not implemented, despite existing in Cashier** (do not call, do not document as working):
`$user->subscription(...)->cancel()/resume()/swap()`, `subscribedToProduct()`, `trialEndsAt()`,
`onGenericTrial()`, `hasIncompletePayment()`, `deletePaymentMethods()`,
`updateDefaultPaymentMethod()`, `upcomingInvoice()`, `tab()`/`invoiceFor()`, coupons/promotion
codes, proration, quantity mutation (`incrementQuantity` etc.).

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
│   ├── CheckoutSession.php          # contract, NOT a DTO — drivers return their own
│   ├── PaymentMethodType.php        # interface over a driver-owned enum
│   └── WebhookHandler.php
├── DTO/                 # Spatie Laravel Data classes
│   ├── Customer.php, Payment.php, Subscription.php, SubscriptionItem.php
│   ├── Invoice.php, InvoiceLine.php, PaymentMethod.php
│   ├── Refund.php, CheckoutRequest.php, WebhookPayload.php
├── Enums/               # String-backed BackedEnum
│   ├── PaymentStatus.php, SubscriptionStatus.php, Currency.php
│   ├── RefundReason.php, WebhookEvent.php, BillingReason.php
│   ├── Interval.php, CheckoutMode.php, SwapTiming.php
│   └── Capability.php               # Granular feature flags per provider
├── Exceptions/          # Hierarchy from CashierException
│   ├── CashierException.php, PaymentFailedException.php
│   ├── IncompletePaymentException.php, CustomerNotFoundException.php
│   ├── InvalidConfigurationException.php, WebhookVerificationException.php
│   ├── SubscriptionUpdateFailure.php
│   ├── UnsupportedOperationException.php  # Thrown for unsupported capabilities
│   └── UnexpectedWebhookEventException.php # Gateway sent an event the driver skips
├── Concerns/            # Traits for Billable model
│   ├── ManagesCustomer.php, ManagesSubscriptions.php
│   ├── ManagesPaymentMethods.php, ManagesInvoices.php
│   ├── PerformsCharges.php, HandlesCheckout.php, HandlesTaxes.php
├── Builders/
│   └── GuardedSubscriptionBuilder.php  # wraps a provider's builder, gates every
│                                        # capability it exposes (trials, quantity, metadata)
├── Events/              # Laravel events
│   ├── WebhookReceived.php, WebhookHandled.php
│   ├── SubscriptionCreated/Updated/Canceled.php
│   ├── SubscriptionRenewed.php, SubscriptionPastDue.php
│   ├── SubscriptionPriceChangeScheduled.php   # scheduled, not yet in effect
│   ├── PaymentSucceeded/Failed.php, RefundProcessed.php
├── Models/              # Abstract Eloquent — READ-ONLY: predicates + relations, no mutators
│   ├── Subscription.php, SubscriptionItem.php, Customer.php
│   └── Invoice.php              # Local invoice model (provider-independent)
├── Invoice/                     # Invoice generation (shared, not provider-dependent)
│   ├── InvoiceBuilder.php       # Build invoice from local payment/subscription data
│   └── InvoiceRenderer.php      # concrete class, hard-bound to spatie/laravel-pdf (#33)
├── Gateway/                     # Traits a driver mixes in (DB reads/writes — real logic)
│   ├── ManagesCustomerRecords.php, ManagesLocalInvoices.php
├── Billable.php         # Meta-trait, includes all Concerns
├── CashierManager.php   # Macroable driver manager + per-driver model registry
├── Facades/Cashier.php  # Facade over the manager
└── CashierSupportServiceProvider.php
```

## Rules

- `declare(strict_types=1)` everywhere
- DTOs — extend `Spatie\LaravelData\Data` (`spatie/laravel-data`)
- Enums — `string`-backed, `snake_case` values
- Money — `int` (minor units) + `Currency` enum, never `float`. A money library
  (`moneyphp/money`, as both references use) is **allowed** if a task needs one — it was left
  out only because we had never used it, not as a ban. See #32.
- Method names strictly from Stripe Cashier — on the surface an app calls. Where Cashier
  encodes a concept without naming it (inline comparisons, a static flag, no such type),
  an internal predicate may be coined; cite the reference lines it encodes in its docblock.
  See `.claude/rules/constraints.md`
- PSR-12 (Pint), PHPStan level 8+ (Larastan)
- Concerns delegate through `CashierManager` (`Cashier::provider()`), never `app(GatewayProvider::class)`
- Concerns call `Cashier::ensureSupports(Capability)` before delegating
- No custom workarounds for unsupported features — throw `UnsupportedOperationException`
- Capabilities gate the caller's INTENT, and every builder setter is gated — see
  `.claude/rules/capabilities.md`
- Billing failure → `CashierException`; malformed argument → `InvalidArgumentException` —
  see `.claude/rules/exceptions.md`

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
    case SubscriptionSwapImmediate = 'subscription.swap.immediate';
    case SubscriptionSwapAtPeriodEnd = 'subscription.swap.at_period_end';
    case SubscriptionTrials = 'subscription.trials';
    case SubscriptionQuantity = 'subscription.quantity';
    case SubscriptionMetadata = 'subscription.metadata';
    case PaymentMethodsAdd = 'payment_methods.add';
    case PaymentMethodsList = 'payment_methods.list';
    case PaymentMethodsDelete = 'payment_methods.delete';
    case CheckoutPrices = 'checkout.prices';
    case CheckoutAmount = 'checkout.amount';
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
    $user->pauseSubscription('default');
}
```

## How a provider connects

```php
// In the ServiceProvider of a concrete package (cashier-revolut)
Cashier::extend('revolut', fn ($app) => $app->make(RevolutGateway::class));
Cashier::useModels('revolut', ['subscription' => RevolutSubscription::class /* ... */]);
```

## Known divergences from the reference (audited 2026-07-14)

A five-way audit compared this package against `vendor/laravel/cashier` and
`vendor/laravel/cashier-paddle` method-by-method. Result: **~35% of Stripe's Billable surface,
~60-65% of the gateway-neutral subset**. Conformance was scored 6/10 — the abstraction is real,
but the subscription mutation surface was reinvented without a multi-gateway reason.

**Do not "fix" any of these by inventing a local workaround — each has an open issue.**

Correctness bugs (fix first; each is self-contained):
- **#24** No unique key on `cashier_subscription_items` → a redelivered webhook duplicates rows.
- **#25** `active()` is really Cashier's `valid()`. A `past_due` subscription in its grace period
  still gets access; Stripe/Paddle deny it (`$deactivatePastDue = true`). No toggle exists.
  Scope note: `unpaid` / `incomplete_expired` are already denied unconditionally via
  `SubscriptionStatus::deniesAccess()` (#22) — Stripe has no toggle for those two. What is
  left to #25 is the rename plus `$deactivatePastDue` / `$deactivateIncomplete`.
- **#26** `config('cashier-support.models.customer')` is read by `CashierManager` but absent
  from the published config — dead branch.
- **#27** `GuardedSubscriptionBuilder` is in no deptrac layer, so the "zero HTTP" rule misses it.

Structural:
- **#28** `GatewayProvider` is not segregated → **any** new operations method is an instant
  BC-break for every driver. This is the root blocker: #30/#35/#36/#37 all queue behind it.
- **#29** `Models\Subscription` has zero query scopes (the references have 17).
- **#39** *(breaking, undecided)* Mutations live on `Billable`, not the model. **The open
  question is whether we hold API compatibility with Cashier at all.** Until that is answered,
  do not "restore" Cashier-style methods on the model on your own initiative.

Abstraction cannot express (not "the driver lacks it" — the contract lacks it):
- **#31** tax / discount / subtotal are absent from `DTO\Invoice` and `DTO\InvoiceLine`.
  A VAT invoice is not representable. Coupons/promotions have no `Capability` case at all.
- **#32** no money formatting API (`formatAmount`, `currency_locale`), and `Currency` is a
  closed 15-value whitelist. `moneyphp/money` is an acceptable fix — the old "no money library"
  note was habit, not a constraint.
- **#33** `InvoiceRenderer` is a concrete class hard-bound to `spatie/laravel-pdf`, whose engine
  (Node + headless Chrome) is only a `suggest` — PDF does not work out of the box.
- **#34** `FakeGateway` lives in `tests/` and is not shipped → apps cannot `Cashier::fake()`.
- **#35** `DTO\Payment` has no `clientSecret` → a 3DS/SCA payment cannot be completed.
- **#30** `Paused` has no `paused_at` column and no pause timing → "pause at period end" is
  not representable.
- **#36** a customer can be created but never updated/synced.

Where we are deliberately **better** than the references (keep it this way):
signature verification is mandatory (both references skip it when the secret is unset),
webhook throttling, transient-only retry with backoff, idempotency on a unique index,
`SwapTiming` instead of a boolean swap flag, `CheckoutRequest` typing, PHPStan 8 + deptrac.

## Navigating this package — use the graph, not grep

`graphify-out/` is a **local build artifact and is not in git** — a fresh clone has no graph
until you build one. Once `graphify-out/graph.json` exists, start there instead of reading
`src/` file by file:

```bash
graphify update .                                               # build/refresh it (AST, seconds)
graphify query "how does a Concern reach the gateway driver"   # scoped subgraph
graphify explain "Capability"                                   # one concept + neighbours
graphify path "Billable" "GatewayProvider"                      # how two things connect
graphify affected "SubscriptionStatus"                          # what breaks if I change X
```

`graphify update` builds the AST layer only — no API key, no cost. The semantic layer
(INFERRED edges, hyperedges, community names) needs an LLM: `graphify extract . --mode deep`
with a backend key set (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, …), or without one graphify
leaves `Community N` placeholders. Everything works without it; the queries are just blunter.

The last graph carrying that layer is still in git history if you want it back rather than
rebuilt: `git show cb795a5:graphify-out/graph.json > graphify-out/graph.json`. It reflects the
code as of that commit — run `graphify update .` after to bring the AST layer forward.

Do not commit `graphify-out/`. It used to be tracked so a clone could inherit the semantic
layer; the post-commit hook rebuilds without a key and the rebuild is lossy, so tracking
eroded that layer commit by commit while adding ~27k lines of diff to unrelated PRs.

`GRAPH_REPORT.md` is for broad architecture review only — prefer the scoped commands.
