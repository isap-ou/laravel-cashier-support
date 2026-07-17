# Changelog

All notable changes to `isapp/laravel-cashier-support` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

> The first release, **1.0.0**. The package was never published to a consumer, so
> its pre-release history has been collapsed into this one entry rather than
> carried as a version trail that describes tags nobody ever installed.

### Added

- **A subscription whose payment never arrived can now be read at all.**
  `SubscriptionStatus` mirrored six of Stripe's eight statuses, and the model casts the
  column through `BackedEnum::from()` — so a row a driver wrote as `unpaid` or
  `incomplete_expired` did not report a lost customer, it threw a `ValueError` on read.
  Both cases now exist, and `SubscriptionStatus::deniesAccess()` gives them the semantics
  Stripe gives them: `Models\Subscription::active()` returns `false` for either one
  regardless of `ends_at`. That guard is the point. Adding the cases alone would have
  traded a loud failure for a quiet one: `active()` grants access on `onGracePeriod()`
  alone, so an unpaid subscription would have kept serving on the strength of a
  paid-through date it never earned. Stripe excludes exactly these two unconditionally,
  while gating `past_due` and `incomplete` behind `$deactivatePastDue` /
  `$deactivateIncomplete`. Those two, and the toggles, are #25 and are untouched here. (#22)

- `Exceptions\UnexpectedWebhookEventException` — "the gateway sent a body this driver
  cannot read as an event" is provider-agnostic, and it used to be a driver-private type
  thrown from a contract method: undeclared, and uncatchable without naming the driver.
  `Contracts\IncomingWebhook` declares what each of its methods throws, and
  `ExceptionBoundaryTest` sweeps it — the webhook contract had escaped that sweep, which
  is precisely why the hole survived.

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
  "extend entitlement, send receipt" on. It is a typed event because a gateway may not
  be able to classify a renewal at parse time — Revolut only says "an order completed",
  and that it paid for a cycle is learned after a refetch.

- **`Events\SubscriptionPastDue`** — a failed payment is not "something changed".
  Dunning, grace-period warnings and suspension all need their own signal instead of
  inferring it from `SubscriptionUpdated`.

- **Invoices are tied to what they paid for.** New nullable `subscription_id`,
  `period_start`, `period_end` and `billing_reason` (`Enums\BillingReason`) on
  `cashier_invoices`, plus `Models\Invoice::subscription()`. A renewal invoice was
  previously unlinkable to either the subscription or the cycle — and this package
  renders these invoices to PDF, so an invoice that cannot state its service period
  is not a usable invoice.

- **`php artisan cashier:webhook {provider?}`** registers this app's endpoint with a
  gateway, replacing a per-driver command. It takes the URL from the **named route**
  (`route('cashier.webhook', ['provider' => ...])`), never from config — a driver's own
  command built it from its own config key, and a key that drifts from the route
  registers a webhook the gateway gets a 404 from on every delivery: no error, no log,
  subscriptions simply stop updating. Stripe already does it this way. (#48)

  A driver opts in by implementing **`Contracts\RegistersWebhooks`**, and the choice of an
  interface over a `Capability` is the references' own disagreement made structural:
  Stripe ships `cashier:webhook` because it has an API for creating endpoints; Paddle
  ships no `Console` directory at all because it does not. Not implementing the interface
  *is* the declaration, and unlike a flag it cannot disagree with the fact. A driver that
  cannot register says so and exits non-zero rather than pretending.

  It returns **`DTO\WebhookRegistration`**, whose `$secret` is nullable because the
  gateways genuinely differ: Revolut returns a signing secret once and never again, while
  Stripe returns none and sends you to the dashboard. `null` means exactly "this gateway
  does not hand it back here" — a driver that expected one and did not get it throws, so
  the operator learns the endpoint exists and needs cleaning up. Not a plain string: a
  Stripe-shaped driver would return `''`, and an empty string meaning "no such thing" is
  the sentinel this package removed from its webhook payload in the first place.

- `CashierManager::registeredDrivers()` — the names a provider package registered.
  `Manager::getDrivers()` cannot answer it: that returns already-resolved instances, so it
  is empty until something resolves one.

### Changed

- **`CustomerOperations::createCustomer()` now takes `DTO\CustomerDetails`, not
  `array $options`. Every driver must change.** (#36) This is a signature change, so it is a
  fatal — "Declaration must be compatible" — for a driver that overrode the old shape;
  `BaseGateway` made *adding* a method safe (#28), not changing one. The coordinated release
  it costs was accepted rather than leaving two neighbouring methods of one interface in two
  different shapes: `updateCustomer()` had to be typed, and a contract half-typed is worse
  than either.

  Apps are unaffected. `$user->createAsCustomer(['name' => '…'])` still takes an array and
  still means what it did — the concern resolves it into `CustomerDetails` and the bag never
  reaches a driver, which is the point. What changes for a driver is that it is handed a name
  instead of having to go and guess which attribute of the app's model holds one.

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

### Added

- **Seat-based billing is now possible at all.** (#37) Quantity could be set once, on the
  builder at creation (`Contracts\SubscriptionBuilder::quantity()`), and never again — no
  increment, no decrement, no update, anywhere. An app that billed per seat could sell a
  subscription and then had no way to add a seat to it.

  `Contracts\SubscriptionOperations::updateSubscriptionQuantity()` is new, and on `Billable`
  there are three ways to reach it: `updateSubscriptionQuantity('default', 5)` sets the count,
  `incrementSubscriptionQuantity()` / `decrementSubscriptionQuantity()` move it relative to what
  is stored. **The gateway only ever learns the absolute number.** Both references make increment
  and decrement compositions over `updateQuantity()` rather than operations of their own, so the
  arithmetic stays on this side of the boundary and a driver implements one method.

  Three guards, each taken from what the references agree on: a quantity below 1 raises
  `InvalidArgumentException` (zero seats is a cancellation, and it has its own method); a
  subscription billed on several prices refuses a change that names none of them; decrement
  floors at 1. Two more are ours, because the edges are:

  - **A count below 1 is refused, which neither reference does.** Theirs will happily take
    `decrementQuantity(-5)`, compute `max(1, 3 - -5)` and bill for **eight** seats — a method
    named *decrement* raising the bill, silently, past a floor that cannot catch it. Direction
    is what these methods are for, so an argument that reverses it is a typo, and
    `.claude/rules/exceptions.md` makes that the caller's bug rather than a billing failure.
  - **An unknown quantity cannot be incremented.** Our `quantity` column is nullable and null
    means *unknown*, so a relative change has nothing to build on; increment/decrement raise
    `SubscriptionUpdateFailure` rather than treat unknown as zero and bill a seat count they
    invented. Setting a quantity outright still works — not knowing where you are does not stop
    you naming where to go.

  **`$price` is optional on `Billable` and required on the contract**, which is the one place
  this deliberately parts from both references. Theirs is `updateQuantity($quantity, $price =
  null)` on the *subscription*, which holds its own items and can resolve "the only one" itself.
  Ours is on the gateway, which holds no local records — so null there would mean asking a driver
  to guess which line to bill. The concern resolves it against the local items and refuses the
  ambiguous call, and by the time a driver is asked, the answer is named.

  **`Capability::SubscriptionQuantityUpdate` is a 22nd case, kept apart from
  `SubscriptionQuantity`** — the same shape as `Customers`/`CustomersUpdate` above and for the
  same reason. Billing per seat at creation and being able to restate the count later are
  different facts about a gateway, and folding the method into `SubscriptionQuantity` would have
  taken the *builder setter* away from every driver that has not written the mutation yet, since
  a capability holds only when every method in `Capability::methods()` is overridden.

- **`Builders\GuardedSubscriptionBuilder::quantity()` now throws the exception it always
  documented.** (#37) `Contracts\SubscriptionBuilder:40` has declared
  `@throws InvalidArgumentException When the quantity is not positive` since it was written, and
  nothing validated it — so `->quantity(0)` reached the driver, and a caller's typo would come
  back from the gateway as a *billing* failure the app is invited to catch and swallow. This is
  the second instance of the exact defect `.claude/rules/exceptions.md` was written about the
  first time (`charge()`, same promise, same silence), which is why the rule now has two
  citations instead of one.

  Neither reference guards here (Stripe's builder checks only which price a quantity belongs to,
  `SubscriptionBuilder.php:154`; Paddle's is a bare assignment, `:44`) — their silence does not
  outrank a promise this package already made. Stripe's `?int $quantity` is deliberately not
  copied along with the guard: `null` there means "send no quantity", which is what a metered
  price needs, and our contract types this `int`, so that state is already inexpressible.

  Found by pulling the thread on the quantity *mutation* guard: refusing
  `updateSubscriptionQuantity(0)` while waving `->quantity(0)` through is one question answered
  two ways.

- **Four gateway-neutral helpers both references have and we never wrote.** (#37)
  `trialEndsAt()`, `hasDefaultPaymentMethod()`, `hasPaymentMethod()` and `deletePaymentMethods()`.
  None is a new capability or a new contract method — each composes an operation this package
  already owned and inherits its gate. Two are narrower than the Cashier method they are named
  after, and say so in their docblocks rather than quietly answering a different question:

  - `trialEndsAt()` reads the subscription and nothing else. Both references consult a *generic*
    trial first — one held before any subscription exists — and we have no storage for one; the
    references do not even agree where it should live (Stripe on the billable's own table, Paddle
    on the customer row). A narrower question, not a different answer to the same one.
  - `hasDefaultPaymentMethod()` asks the gateway, where Stripe reads a locally cached column. We
    cache nothing, so it costs a round-trip and it can refuse — catchably — where Stripe's would
    return a confident `false` it never checked. Do not put it in a loop.
  - `hasPaymentMethod()` / `deletePaymentMethods()` take a `Contracts\PaymentMethodType` where
    Stripe takes a string, because there the strings *are* Stripe's own wire values and there is
    no such vocabulary here to borrow. A plain string still works, compared against the enum's
    backing value.

- **A customer can now be corrected, and a model can say where its own name lives.** (#36)
  A customer could be created and never updated: a user changed their email in the app and
  nothing could push it, so the gateway's record drifted from ours permanently.

  `Contracts\CustomerOperations::updateCustomer()` is new, and on `Billable` there are three ways
  to reach it — `updateCustomer(['email' => '…'])` changes named fields, `syncCustomerDetails()`
  makes the gateway match the model, `updateOrCreateCustomer()` branches on whether there is a
  customer yet. **Create fills in blanks from the model and update deliberately does not**: create
  has no prior state, update does, and auto-filling an unmentioned field would silently overwrite
  it. Stripe draws the same line — `updateStripeCustomer()` is a bare passthrough and
  `syncStripeCustomerDetails()` is the one that reads the hooks. Paddle cannot draw it either way:
  it has no customer update at all, which is the same fact that made this a capability.

  `cashierName()` / `cashierEmail()` are the new seam. Override them when the model keeps its
  name elsewhere. Before them a model had no way to declare where its identity lived, which left
  a driver two moves and the usual one was the worse: reach into the app's model and guess an
  attribute. That is the coupling this package exists to remove, and it was happening in the one
  place nobody looks.

  **`updateCustomer` is `Capability::CustomersUpdate`, not `Customers`** — a 21st case. Having
  customers and being able to change one are different facts about a gateway, and the references
  settle it rather than suggest it: Stripe pushes name/email out, Paddle has no customer update at
  all. Folding the method into `Customers` would also have stripped that capability from every
  driver that had not written an update yet, since a capability holds only when *every* method in
  `Capability::methods()` is overridden — silently, because a lie about capabilities reads exactly
  like the truth until an app calls the method.

- **`DTO\CustomerDetails`** — the typed thing a driver now receives where it used to get an
  untyped `array<string, mixed>`. Two fields, `name` and `email`, because that is precisely what
  the two references agree on; Stripe's other four are Stripe's, and `preferred_locales` is not
  even a concept but a field name in its request body. Typing those here would carve one
  gateway's schema into the package that must not know any gateway exists. Anything else rides in
  `options`, declared as the provider-specific escape hatch — the shape `DTO\CheckoutRequest`
  already uses. A `null` field means "not specified", never "set to empty".

- **`Gateway\BaseGateway` — adding a method to a contract no longer breaks every driver.** (#28)
  `GatewayProvider` bundles all seven operations interfaces and nothing shipped default
  implementations, so one new contract method was an instant fatal in every driver: not a
  deprecation, a driver that did not implement it stopped loading. That is why the features that
  need a new method queued behind each other instead of landing independently.

  Extend `BaseGateway` and every contract method already has a body that throws
  `UnsupportedOperationException` — grouped one trait per operations contract under
  `Gateway/Defaults/` (`RefusesCharges`, `RefusesSubscriptions`, …), composed into the base class, so
  each new contract method has an obvious home next to its own contract. Override what the gateway
  genuinely does. Adding a method to a contract **and to its `Refuses*` trait in the same commit** is
  now inherited by every driver that extends `BaseGateway` — it keeps loading and reports the new capability unsupported on its own. Nothing is
  removed and nothing is required to change: a driver that implements `GatewayProvider` directly,
  as before, still works exactly as it did.

  **The contract was deliberately NOT segregated.** Splitting the operations into opt-in interfaces
  and asking `$provider instanceof SupportsPause` was the obvious reading of #28 and it was
  rejected: drivers are *drop-in replacements for each other*, so an app moving from one gateway to
  the next would get `Call to undefined method` where it used to catch a `CashierException`, and
  would have to ask `instanceof` at every call site — putting the gateway back in the caller's
  head, which is the coupling this package exists to remove. A driver's throwing stubs were never
  the disease; they are how substitution works. The only real complaint was that each driver
  hand-wrote them.

  It is a base class, and the defaults are composed **into** it rather than mixed into drivers, for
  a reason that is not style: `Gateway\ManagesLocalInvoices` already implements
  `invoices()`/`findInvoice()`/`downloadInvoice()`, and a class using two traits that define the same
  method is a fatal collision. A driver mixing in the defaults would have had to write `insteadof`
  per collision and edit that list whenever support added a method — the very BC break this removes.
  PHP resolves methods as own class, then trait, then parent, so inherited from the base a default is
  beaten by a driver's trait with no ceremony at all. The corollary: a driver must not `use` a
  `Defaults\*` trait directly.

- **`Enums\Capability::methods()` — a driver's capabilities are read off its code, not its word.**
  12 of the 20 cases map to the gateway methods that implement them, so `BaseGateway::supports()`
  answers by checking what the driver actually overrode; a capability holds only when *every* one
  of its methods does (a gateway that lists invoices but cannot render one does not support
  `Invoices`).

  `capabilities()` and `supports()` are **final**, for the reason `Builders\GuardedSubscriptionBuilder`
  is a final class: the gate is not the driver's to make, and a lie about capabilities reads exactly
  like the truth until an app calls the method. Nothing legitimate is lost — what they derive is a
  structural fact, and `declaredCapabilities()` remains the extension point, asked on every call. A
  gateway that genuinely needs its own `supports()` implements `Contracts\GatewayProvider` directly,
  as drivers do today. The known limit, stated rather than hidden: an operation that is implemented
  but disabled per account cannot report itself unsupported — `supports()` answers "can this gateway
  do this at all", and a runtime refusal is a `CashierException`.

  The remaining 8 map to nothing and are declared by hand, because no method can
  express them: `swapSubscription()` is **one** method behind `SubscriptionSwapImmediate` and
  `SubscriptionSwapAtPeriodEnd` — Revolut can defer a swap but not do it now — `checkout()` is one
  method behind `CheckoutPrices` and `CheckoutAmount`, and Trials/Quantity/Metadata/Taxes are
  setters on `SubscriptionBuilder`, which is not the gateway. That split is precisely why
  interfaces alone could never have replaced this enum.

### Fixed

- **Every `illuminate/*` package `src/` imports from is now declared, and a test keeps it
  that way.** `composer.json` did not require `illuminate/queue`, and nothing it did require
  reached it — yet all 11 classes in `src/Events/` imported `Illuminate\Queue\SerializesModels`.
  A `use` on a class from an undeclared package is a fatal at class load, so under a
  subsplits-only install every event class in this package was unloadable. (#43)

  **`SerializesModels` stays and is now declared honestly.** It is load-bearing: 9 of the 11
  events carry a `Model $billable`, and the trait replaces the model with a `ModelIdentifier`
  and re-fetches it across the queue, so a listener sees the row as it *is* rather than as it
  looked at dispatch. `illuminate/queue` ships as a subsplit, so declaring it costs nothing —
  `laravel/framework` already `replace`s it, and adding the requirement installs no new package
  in a real Laravel app.

  **`Illuminate\Foundation\Events\Dispatchable` is gone, because it could not be declared at
  all.** Foundation has no subsplit for modern Laravel — `illuminate/foundation` on packagist is
  abandoned at v1.1.2 (2012) and cannot satisfy `^11|^12|^13`, and it is absent from
  `laravel/framework`'s `replace` list where `illuminate/queue` is present. The only honest way
  to keep the import was to require `laravel/framework` from a library. It bought nothing: the
  whole trait is four static methods, three of them one-liners over `event(new static(...))` and
  the fourth over `broadcast()`; it was used **zero** times in `src/` — events are dispatched with
  `event(new ...)` — and it is sugar for whoever *sends* an event, while an app is our events'
  listener. Events are still dispatched and still serialize
  identically; only `SubscriptionCreated::dispatch(...)` is gone, in favour of
  `event(new SubscriptionCreated(...))`.

  **The reasoning is recorded here because the position it replaces was reached by an argument
  that was wrong.** #41 defended the undeclared traits with "both references are in exactly the
  same position". Verified on disk, that is half true and the wrong half was load-bearing:
  `laravel/cashier` requires `illuminate/notifications`, which requires `illuminate/queue`, so
  Stripe *is* covered — incidentally, since it pulls notifications to mail about failed payments,
  not to obtain a trait. `laravel/cashier-paddle` requires no such thing and none of its six
  `illuminate/*` deps reach queue, so Paddle was in exactly our position. The references disagree
  here, which is why neither settled it.

  `tests/Feature/DeclaredDependenciesTest.php` now asserts that every `Illuminate\` root imported
  by `src/` maps to a declared package. Composer cannot catch this — it reads `composer.json` and
  never our imports — and #43 was filed precisely because the same gap had already been found
  once and written into a PR body, where it went untracked the moment that PR merged.

- **An event the package never mapped now reaches a listener, and no driver can get that
  wrong again.** The webhook entry point moved into this package: one route,
  `webhook/cashier/{provider}`, one controller, and one contract method per driver.
  (#42, #46, #47, #48)

  **This was an ordering bug, and ordering was the wrong thing to leave to drivers.**
  `WebhookReceived` exists to fire for every verified webhook *before* any decision about
  what it means, precisely so an app can react to what we never mapped. In a driver's own
  controller it sat *below* the parse step — which threw for exactly the events the hatch
  existed for, so they reached nobody and vanished behind a 200. `cashier-revolut` maps 8
  of Revolut's 22 documented event types, so 14 disappeared, every `DISPUTE_*` among them:
  a customer disputing a charge reached no listener at all. Of the five things that
  driver's controller did, **one** was gateway-specific; the other four were copied
  generic steps, and each new driver got a fresh chance to interleave them wrong.
  `-adyen` and `-wise` would have had the same chance. Now the order lives here, once.

  **`Contracts\WebhookHandler` is one method**: `webhook(string $content, array $headers):
  IncomingWebhook`. The delivery it returns has two, and the controller calls them around
  the hatch — `parse()` verifies and reads the body, then `WebhookReceived`, then
  `pipeline()` applies it. That mirrors the reference step for step
  (`laravel/cashier`'s `Http/Controllers/WebhookController.php:42-58`).

  **`pipeline()` returns `bool`, and the rule it carries replaces the ordering**: an event
  the driver does not map returns `false` and MUST NOT throw. One sentence on one method,
  under test, instead of a sequence every driver had to reproduce. The bool is Stripe's
  `method_exists($this, $method)` check moved behind the contract, and it is what keeps
  `WebhookHandled` honest — without it that event would fire under identical conditions to
  `WebhookReceived`, carrying identical data, which is a second name for one event rather
  than a signal.

  **The events carry `$provider`.** Both references answer "whose webhook is this?" with
  the class — `Laravel\Cashier\Events\WebhookReceived` and `Laravel\Paddle\Events\WebhookReceived`
  are different types, and the two packages cannot even be installed side by side. One
  shared event per driver has no such discriminator, so an app would get a
  provider-shaped array with no way to read it: invisible with one driver, a guess with two.

  **The payload is the raw decoded body, and provider-shaped on purpose.** An event nobody
  mapped has no agnostic meaning to render, and inventing one would be the lie the hatch
  exists to prevent. Both references dispatch a raw array here for the same reason
  (`laravel/cashier`'s `:45`, `-paddle`'s `:49`). Agnostic meaning already travels on the
  nine **typed** events — `PaymentSucceeded`, `SubscriptionCreated`, `SubscriptionRenewed`
  and the rest — which carry the billable and a real DTO.

  **Verification stays mandatory, and now provably runs first.** Both references attach
  their signature middleware only `if (config(...secret))` and otherwise accept unsigned
  webhooks with no throw and no log line (`laravel/cashier`'s `WebhookController.php:29`,
  `-paddle`'s `:32`). We refuse instead. This package fixes *when* verification runs —
  above anything dispatched or applied — though it cannot prove *that* a driver ran it;
  that half stays contractual and driver-tested.

  For an app: listen to `WebhookReceived`, read `$event->provider` and `$event->payload`.
  The route is configured by `cashier-support.webhook.prefix` / `.methods` /
  `.middleware` (throttled by default — neither reference rate-limits its webhook). The
  prefix is a prefix, not the whole path: the driver segment is appended by the route, so
  it cannot be configured away into a route that registers cleanly and then fails every
  delivery. Stripe splits it the same way.

  `methods` defaults to `['POST']`, which is what both references hardcode — but each of
  them serves exactly one gateway, so that is two data points rather than a law about
  gateways. Until this package owned the route, a driver declared its own and could
  differ; the key is what gives that back, so a gateway that verifies its endpoint with a
  GET is configured for rather than unreachable.

- **`cashier_subscription_items` now constrains what it always meant.**
  `unique(subscription_id, price)` — a subscription bills a given price once.

  **The references disagree here, and this follows Paddle.** Paddle states the invariant in
  exactly this shape (`unique(['subscription_id','price_id'])`). Stripe declines it: it puts
  a deliberately **non**-unique `index(['subscription_id','stripe_price'])` on the same pair
  and constrains `unique(stripe_id)` instead — the provider's item id, a different
  invariant. Stripe can afford that because every item it writes carries a `stripe_id`; the
  schema guards the identity and the API guards the rest.

  We cannot follow Stripe, and the reason is our own table rather than a judgement about
  theirs: our `provider_id` is **nullable** and a driver may legitimately never write it —
  cashier-revolut does not — so the analogous `unique(provider, provider_id)` would guard
  rows that hold `(revolut, NULL)`, and NULLs do not collide in a unique index. It would
  constrain nothing for the one driver we have. Paddle's shape needs no provider id, which
  is why it is the one that ports.

  This is defense in depth, not a live bug, and the distinction is worth keeping straight:
  the only writer that exists — `cashier-revolut`'s `persistPlanVariation()` — already
  serializes a `lockForUpdate()` read-modify-write inside a transaction, so a redelivered
  webhook does **not** duplicate a row today. But that lock is one driver's discipline and
  the table is shared, so nothing except the schema stops the next driver from inserting a
  second row for a price the subscription is already billed on. Nothing downstream would
  notice if it did: `subscribedToPrice()` sees the price twice and still returns `true`.

  The constraint is on the **pair**. `subscription_id` alone stays unconstrained, because a
  Stripe-shaped subscription legitimately carries several distinct prices — covered by a
  test, so the unique key cannot quietly become the thing that forbids multi-item drivers.

  Added in place rather than as a follow-up migration, and with no dedupe step: the package
  has never been published, so there are no installs holding duplicates to clean up. (#24)

- **Publishing `cashier-support.models.*` now overrides the driver's models — including
  `customer`, which the array never named.** Two defects, and fixing either alone would
  have left the feature as decorative as it was:

  The `models` array named `subscription`, `subscription_item` and `invoice`, but
  `CashierManager::model()` resolves **four** slots. `customer` was one the manager read and
  the config never offered, so an app had to reach for `Cashier::useModels()` — the driver's
  mechanism, not the app's. The default is `null`, matching the other three; it may **not**
  be the abstract `Models\Customer`, because the override is gated on
  `is_subclass_of($class, $abstract)`, false when the two are the same class, so an abstract
  default would make a stock install throw on first use.

  More to the point, **the config outranks the driver's registry now; before, it lost to
  it.** `model()` read the registry first and consulted config only when the registry held
  nothing for that slot — so for every slot a driver *did* register, the published value was
  unreachable. cashier-revolut registers all four, so in a cashier-revolut install
  publishing the config changed nothing whatsoever. The config was reachable only for a slot
  its driver left empty, which `hasModel()`'s own docblock contemplates ("a driver that
  stores no customers is a legitimate driver") — so the feature worked precisely where the
  app was least likely to need it, and not where it was. Adding the missing `customer` key
  without this would have shipped a fourth line just as unreachable as the other three.

  A config value that is named but unusable now **fails** instead of falling through to the
  driver, and says which of the two is at fault — an override that loses silently is the
  same defect as one that is never read, and blaming the driver for the app's typo is how an
  afternoon gets lost.

  `tests/Feature/ModelConfigOverrideTest.php` covers all four slots, the precedence, and
  both failure paths. No test had ever supplied a value *through* the config. It asserts the
  published array against the **file**, not the container: `config()->set()` creates the key
  whether or not the stub declares it, so a runtime-override test alone would have stayed
  green through the entire lifetime of this bug. (#26)

- **A queued listener no longer grants access on a stale snapshot.** All 11 events in
  `src/Events/` now `use Dispatchable, SerializesModels;`, as every event in both
  references does (`laravel/cashier`'s `Events\WebhookReceived`, `cashier-paddle`'s
  `Events\SubscriptionCreated`). Nine of them carry a `Model $billable`, and without
  `SerializesModels` PHP serialized that model **by value**: the whole row — every
  attribute, plus Eloquent's `original` copy of it — went into the queue payload, and
  the listener worked from the row as it looked at dispatch time, however long the job
  had been waiting.

  That is worst exactly where it costs most. `SubscriptionCreated` and
  `PaymentSucceeded` listeners are what grant access, and they are the ones most likely
  to be queued. A webhook landing between dispatch and run, a cancellation, a status
  change — none of it reached the job, which then provisioned against a subscription
  that had already moved on. The payload bloat (a full User/Team attribute set per job)
  was the lesser half of it.

  `SerializesModels` replaces the model with a `ModelIdentifier` and re-fetches it in
  the worker, so the listener sees the row as it **is**. `tests/Feature/EventSerializationTest.php`
  pins the behaviour rather than the trait: it pushes a real queued listener onto the
  database queue, asserts the raw `jobs.payload` contains no attribute values, then
  mutates the row before running the worker and asserts the listener observed the new
  value. A reflection check over `class_uses()` would only have restated the diff.

  That proves the mechanism on one event; a third test proves the reach. It sweeps
  `src/Events/` — found, not enumerated, the way `ExceptionBoundaryTest` sweeps the
  contracts — and asserts the payload of every event carrying a billable. Removing
  `SerializesModels` from any one of the nine now fails by name. An event added later is
  covered the day it exists, and one carrying a payload the sweep cannot build fails
  loudly rather than quietly opting out.

  The two DTO-only events (`WebhookReceived`, `WebhookHandled`) have no model to
  reference. They take both traits regardless — for the uniformity the references keep,
  and because `Dispatchable` is wanted on every event either way. `SerializesModels` is
  inert there, and a round-trip test pins that it stays inert.

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
