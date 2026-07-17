# isapp/laravel-cashier-support

## Purpose

Provider-agnostic contracts for Laravel Cashier.
**Primary reference — `laravel/cashier-stripe` v16 (Laravel 12).** Second opinion —
`laravel/cashier-paddle`: where Stripe and Paddle agree, that is the shape of the
abstraction; where they differ, the difference is what a `Capability` is for.
`mollie/laravel-cashier-mollie` is a last resort only — it builds its own local
subscription engine, which the smart-stub rule forbids, so it is not a design authority.

**This package does not know which drivers exist.** Stripe and Paddle are the design
authorities and they are on disk; a driver is not a third one. So a driver's API is never an
argument about the shape of a contract, in either direction — *"our driver cannot do X"* is not
a reason to leave X out, and *"our driver already does X this way"* is not a reason to shape a
method around it. That reasoning yields a description of one gateway wearing generic names,
which is the one thing this package must not be.

A driver may legitimately **motivate** a capability: `.claude/rules/capabilities.md` cites a
gateway that can only swap at cycle end as why `SwapTiming` exists, and that is the rule working,
not breaking. It motivates the capability; it does not shape the contract. The order is read the
references, take what they agree on, and where they differ the difference is a `Capability`.
What any given gateway then declares is not this package's business — and since #28 its refusal
is already written for it.

This is written down because #36 got it wrong twice in one sitting, at both ends: the design
question was first framed as "can our driver update a customer" (it is not the question — the
references' disagreement is), and then a field was argued out of the contract because "our
driver may not have somewhere to put it" (also not the question — one reference having it and
the other not is). Both times the correct argument existed and was available; the driver was
simply the nearer thing to reach for.

**And the direction of work is one-way: support first, drivers after — NON-NEGOTIABLE.** This
package is the source of truth. A change here is designed, reviewed, tested and merged against
**this package alone** — its correctness is what this package's CI and contract tests say, never
what a driver does with it, and never whether a driver currently compiles. When a change breaks a
driver, that is **expected and correct**: it is a coordinated release the *driver* owns, done
**after** support is right — never a reason to hold back, water down, or reshape the fix to keep a
driver green. While working here, do **NOT** open a driver package to "check impact", grep it for
consumers, or let its fallout steer a decision: a BC-break is whatever this package's own tooling
reports on its public surface, and the driver fallout is the driver's issue, tracked separately.
We fix Revolut once support is done, not the other way around.

This package contains mostly: interfaces, DTOs, enums, exceptions, abstract models, traits, events.
**Zero *outbound* HTTP** (enforced by `deptrac.yaml`, whose `HttpClient` layer is unreachable) —
which is not the same as no HTTP: `src/Http/Controllers/WebhookController.php` is the webhook
entry point for every driver, and `routes/webhook.php` mounts it. The rule is that this package
never *calls* a gateway; being called by one is different, and it is deliberate (#47).

It is *not* literally zero business logic — `src/Invoice/` (invoice total summation) and
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
$user->updateCustomer(['email' => '...']);   // only the named fields; unmentioned ones stay
$user->syncCustomerDetails();                // push what the model knows: cashierName()/cashierEmail()
$user->updateOrCreateCustomer(['email' => '...']);
$user->defaultPaymentMethod();
$user->hasDefaultPaymentMethod();             // asks the gateway — Stripe's reads a local column
$user->hasPaymentMethod(AcmeType::Card);      // driver-owned enum, or its value: 'card'
$user->addPaymentMethod('pm_xxx');
$user->deletePaymentMethods();                // all of them, or narrowed to one type
$user->invoices();
$user->downloadInvoice('inv_xxx');
$user->trialEndsAt('default');                // the subscription's trial only — no generic trial here

// Subscription MUTATIONS live on Billable, NOT on the model — unlike Cashier.
// `$user->subscription('default')` returns a read-only model with no mutators.
$user->cancelSubscription('default');
$user->cancelSubscriptionNow('default');
$user->resumeSubscription('default');
$user->pauseSubscription('default', PauseTiming::AtPeriodEnd);   // AtPeriodEnd is the default — see PauseTiming
$user->swapSubscription('default', 'price_yearly', SwapTiming::AtPeriodEnd);
// Quantity mutation is on Billable too, and the gateway is only ever told the absolute number.
$user->updateSubscriptionQuantity('default', 5);
$user->incrementSubscriptionQuantity('default', 2, 'price_seats');   // $price only when several
$user->decrementSubscriptionQuantity('default');                      // floors at 1

// The model is read-only, and its predicates are where access is decided.
// valid() is the access question and what subscribed() asks; active() is narrower.
$user->subscription('default')->valid();              // active, trialing, or paid through ends_at
$user->subscription('default')->active();             // ...and the renewal has not failed
$user->subscription('default')->pastDue();            // the status, never the access policy
$user->subscription('default')->incomplete();
$user->subscription('default')->hasPrice('price_monthly');
$user->subscription('default')->hasSinglePrice();     // ...and hasMultiplePrices()
$user->subscription('default')->paused();             // paused_at in the past — pause in force
$user->subscription('default')->onPausedGracePeriod();// paused_at in the future — pause scheduled, still serving

// Every predicate above that reads a column is also a query scope, and the two agree by
// construction. This is the only way to ask the question without hydrating the row.
User::whereHas('subscriptions', fn ($q) => $q->active())->get();
// valid/active, pastDue/incomplete, canceled, onTrial, onGracePeriod, ended, paused,
// onPausedGracePeriod — and the negations, which have no predicate twin: PHP has `!`, a query
// builder does not.
$user->subscriptions()->notOnTrial()->notCanceled()->get();

// Off the model, the class is per-driver: Models\Subscription is ABSTRACT, so
// `Subscription::query()` is a fatal, not an entry point. Ask the registry for the driver's.
$subscriptions = Cashier::subscriptionModel('revolut');   // ...or () for the default driver
$subscriptions::query()->pastDue()->get();                // the dunning cron, without a table scan

// A past_due or incomplete customer keeps access only if the app says so. Global, not
// per-driver: it is product policy, not something a gateway can answer. Both the predicates
// and the scopes read these.
Cashier::keepPastDueSubscriptionsActive();
Cashier::keepIncompleteSubscriptionsActive();
```

**Not implemented, despite existing in Cashier** (do not call, do not document as working):
`$user->subscription(...)->cancel()/resume()/swap()`, `subscribedToProduct()`/`onProduct()`,
`onGenericTrial()`, `hasIncompletePayment()`, `updateDefaultPaymentMethod()`, `upcomingInvoice()`,
`tab()`/`invoiceFor()`, coupons/promotion codes, proration, `Models\Subscription::recurring()`
(the references disagree on its body — it is a design, not a port) and `scopeRecurring` with it,
`hasExpiredTrial()`/`scopeExpiredTrial`,
item-level quantity (`$item->updateQuantity()` — Stripe-only; Paddle's item is a dumb row, as is
ours).

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
│   ├── WebhookHandler.php           # one method: webhook() → IncomingWebhook
│   ├── IncomingWebhook.php          # one delivery: parse() then pipeline(): bool
│   ├── RegistersWebhooks.php        # opt-in: gateways that create endpoints via API
│   ├── InvoiceRenderer.php          # render an Invoice DTO to bytes — driver-supplied, no engine here
│   └── RendersInvoices.php          # opt-in: gateways that render their own local invoices
├── DTO/                 # Spatie Laravel Data classes
│   ├── Customer.php, CustomerDetails.php, Payment.php, Subscription.php, SubscriptionItem.php
│   ├── Invoice.php, InvoiceLine.php, PaymentMethod.php
│   ├── Refund.php, CheckoutRequest.php, WebhookRegistration.php
├── Casts/               # CurrencyCast (spatie-data), CurrencyEloquentCast — string <-> Money\Currency
├── Enums/               # String-backed BackedEnum
│   ├── PaymentStatus.php, SubscriptionStatus.php
│   ├── RefundReason.php, BillingReason.php
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
│   ├── Concerns/                # Subscription's predicates, each WITH the query scope that
│   │   │                        # must agree with it. Composed into Models\Subscription the way
│   │   │                        # BaseGateway composes Defaults/ — one trait per column-family,
│   │   │                        # flattened into the abstract base. Pairing the two is the point:
│   │   │                        # split across files, predicate/scope drift is invisible (#29)
│   │   ├── DecidesAccess.php        # valid()/active() + scopes; the status lists both read
│   │   ├── ReportsStatus.php        # pastDue()/incomplete() + scopes
│   │   ├── TracksCancellation.php   # canceled()/onGracePeriod()/hasEnded() + scopes
│   │   └── TracksTrialPeriod.php    # onTrial() + scopes
│   └── Invoice.php              # Local invoice model (provider-independent)
├── Invoice/                     # Invoice DATA assembly (shared); rendering is the driver's (Contracts\InvoiceRenderer)
│   └── InvoiceBuilder.php       # Build invoice from local payment/subscription data
├── Gateway/                     # What a driver inherits or mixes in
│   ├── BaseGateway.php          # abstract; composes the Defaults/ traits, adds
│   │                            # capabilities()/supports() derived from what was overridden.
│   │                            # Extend it: that is what makes a new contract method non-breaking (#28)
│   ├── Defaults/                # one trait per operations contract, each method refusing with
│   │   │                        # UnsupportedOperationException. Composed INTO BaseGateway —
│   │   │                        # a driver must not mix these in directly (trait collision)
│   │   ├── RefusesCharges.php, RefusesCheckout.php, RefusesCustomers.php
│   │   ├── RefusesInvoices.php, RefusesPaymentMethods.php
│   │   └── RefusesSubscriptions.php, RefusesWebhooks.php
│   ├── ManagesCustomerRecords.php, ManagesLocalInvoices.php   # traits (DB reads/writes — real logic)
├── Http/Controllers/            # The webhook entry point for EVERY driver
│   └── WebhookController.php    # routes/webhook.php → webhook/cashier/{provider}
├── Console/
│   └── WebhookCommand.php       # php artisan cashier:webhook {provider?}
├── Testing/             # SHIPPED test doubles + driver conformance suite (production autoload)
│   ├── FakeGateway.php          # in-memory GatewayProvider; Cashier::fake() returns it. Hand-passed
│   │                            # capability set; records ops (charges, createdSubscriptions, …) and
│   │                            # exposes assertCharged()/assertSubscriptionCreated()/… (PHPUnit-backed)
│   ├── FakeSubscriptionBuilder.php, FakeCheckoutSession.php   # the fake's collaborators
│   ├── FakeIncomingWebhook.php, FakePaymentMethodType.php
│   └── GatewayConformanceTestCase.php  # abstract; a driver extends it to prove it honours the
│                                        # contract (supports()⇄capabilities(); every op returns its
│                                        # type or throws UnsupportedOperationException). Not for #28's
│                                        # BC guarantee — that is Tests\Fixtures\MinimalGateway.
├── Billable.php         # Meta-trait, includes all Concerns
├── CashierManager.php   # Macroable driver manager + per-driver model registry
├── Facades/Cashier.php  # Facade over the manager; also the static Cashier::fake() test entry point
└── CashierSupportServiceProvider.php
```

## Rules

- `declare(strict_types=1)` everywhere
- DTOs — extend `Spatie\LaravelData\Data` (`spatie/laravel-data`)
- Enums — `string`-backed, `snake_case` values
- Money — amounts are `int` (minor units), never `float`. Currency is `Money\Currency`
  (`moneyphp/money`, as both references use), bridged into DTOs and Eloquent by
  `Casts\CurrencyCast` / `Casts\CurrencyEloquentCast`; any ISO-4217 code is valid.
  `Cashier::formatAmount()` renders an amount locale-aware via `IntlMoneyFormatter` (no
  float, decimals per ISO-4217). See #32.
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
    case CustomersUpdate = 'customers.update';
    case Subscriptions = 'subscriptions';
    case SubscriptionPauseImmediate = 'subscription.pause.immediate';
    case SubscriptionPauseAtPeriodEnd = 'subscription.pause.at_period_end';
    case SubscriptionResume = 'subscription.resume';
    case SubscriptionSwapImmediate = 'subscription.swap.immediate';
    case SubscriptionSwapAtPeriodEnd = 'subscription.swap.at_period_end';
    case SubscriptionTrials = 'subscription.trials';
    case SubscriptionQuantity = 'subscription.quantity';        // a seat count at creation
    case SubscriptionQuantityUpdate = 'subscription.quantity.update';   // ...and changing it later
    case SubscriptionMetadata = 'subscription.metadata';
    case PaymentMethodsAdd = 'payment_methods.add';
    case PaymentMethodsList = 'payment_methods.list';
    case PaymentMethodsDelete = 'payment_methods.delete';
    case CheckoutPrices = 'checkout.prices';
    case CheckoutAmount = 'checkout.amount';
    case Invoices = 'invoices';
    case Taxes = 'taxes';
    case Discounts = 'discounts';                              // an invoice may carry a discount
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

// App-level check — pause is timing-granular, like swap: ask for the timing you need.
if (Cashier::supports(Capability::SubscriptionPauseAtPeriodEnd)) {
    $user->pauseSubscription('default', PauseTiming::AtPeriodEnd);
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

**If a Cashier method seems missing here, it is missing on purpose and it has an issue.** Read
the tracker before building anything around the gap — a local workaround is the one response
that is always wrong (`.claude/rules/smart-stubs.md`):

```bash
gh issue list --repo isap-ou/laravel-cashier-support   # what is open, right now
```

**That command is the status; this section is not.** It describes the *shape* of the gaps, which
outlives any one issue, and it deliberately does not enumerate them: a list of tickets copied
into a doc is a second source of truth, and this file cannot know an issue was closed. It is the
file with no test over it — #38 exists because it once described an API that did not exist, and
the fifteen bullets that stood here had already drifted two issues short of the tracker.

Closing a ticket no longer means editing a list. It may still touch a doc that describes the code
that ticket changes — #33 turned `InvoiceRenderer` into an interface, and the architecture map above
said it was a concrete class, so the map moved with it. That is the map doing its job.

Three things are worth knowing before you plan, because they decide what work is even possible:

**A new contract method is no longer a BC break — but only for a driver that extends
`Gateway\BaseGateway` (#28).** `GatewayProvider` still bundles all seven operations interfaces and
still requires all 19 methods, deliberately: drivers are drop-in replacements, so an operation a
gateway cannot do must still *answer* — catchably — rather than not exist. Segregating into opt-in
`instanceof` interfaces was considered and rejected for exactly that reason; do not re-propose it
without reading `BaseGateway`'s docblock first.

What changed is who writes the refusals. `BaseGateway` ships a default for every contract method
(`throw UnsupportedOperationException`), grouped one trait per contract under `Gateway/Defaults/`, so
**adding a method to a contract + to its `Defaults\Refuses*` trait in the same commit breaks
nothing** — a driver that extends `BaseGateway` inherits the refusal and reports the new capability
unsupported by itself.

**Adding is what became free — changing did not.** Altering an existing method's signature is
still a fatal in every driver that overrode it ("Declaration must be compatible"), and no default
can absorb that. #36 changed `createCustomer()` to take `DTO\CustomerDetails` and paid the
coordinated release for it, deliberately, because typing `updateCustomer()` while leaving its
neighbour an untyped bag would have been worse than either. Know which of the two you are doing
before you price the work.

It is a base class rather than traits a driver mixes in, and that is load-bearing:
`Gateway\ManagesLocalInvoices` already implements three of those methods, and two traits defining the
same method in one class is a fatal collision — so a driver mixing in the defaults would need an
`insteadof` per collision, re-edited on every new method, which is the BC break itself. Inheritance
resolves it by language rule (own > trait > parent), so a driver's trait beats the default for free.
**Do not use a `Defaults\*` trait from a driver** — that walks straight back into the collision.

Two things follow. A driver that does **not** extend `BaseGateway` — `Tests\Fixtures\FakeGateway`
today — still eats the fatal, so the guarantee is opt-in. And `Enums\Capability::methods()` now maps
13 of the 24 cases to the methods that implement them, so `BaseGateway::supports()` reads them off
the code; the other 11 cannot be read off anything (`swapSubscription()` is one method behind two
timings, `pauseSubscription()` one method behind two timings since #30, `checkout()` one method
behind two shapes, four are `SubscriptionBuilder` setters, and `Discounts` backs no operation at
all — an invoice-shape flag, #31) and stay declared via
`declaredCapabilities()`. That split is why interfaces could never have replaced the enum.
**Count those two numbers against the enum before repeating them** — they were wrong here
until #37 (the file said 12 of 20 when the code said 13 of 21), which is #38's whole shape.

**The subscription mutation surface is ours, not Cashier's, and that is undecided (#39).**
Mutations live on `Billable` (`$user->cancelSubscription('default')`); `Models\Subscription` has
no mutators. Whether we hold API compatibility with Cashier at all is **the user's call, not an
agent's** — until it is answered, do not "restore" Cashier-style methods on the model on your own
initiative.

**Some gaps are inexpressible, not unimplemented — this is the trap.** It is not "the driver
lacks it", it is "the contract has nowhere to put it", so no `Capability` flag routes around it
and no driver can supply it. `DTO\Payment` has no `clientSecret`, so an SCA payment cannot be
completed (#35).
Recognising this class matters more than the individual issues: the instinct it triggers — "the
gateway must not support it, I will work around it" — is exactly backwards. `DTO\Invoice` was the
canonical case — no tax, discount or subtotal, so a VAT invoice was not representable — and #31
resolved it by adding the fields to the shape, not by working around the missing ones.

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
