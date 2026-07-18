# Spec: Ship FakeGateway, `Cashier::fake()`, and a driver conformance suite

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#34](https://github.com/isap-ou/laravel-cashier-support/issues/34)

## Context & Goal

`tests/Fixtures/FakeGateway.php` is a complete in-memory `GatewayProvider` with a configurable
capability set — exactly what a host app needs to test its billing code — but it lives in
`autoload-dev` only (`composer.json`). So an app that depends on this package cannot `Cashier::fake()`
and assert against it, and every driver re-mocks `GatewayProvider` by hand. Paddle ships its test
double in `src/` (`vendor/laravel/cashier-paddle/src/CashierFake.php`). For a package whose entire
value proposition is *"the gateway is an interface"*, not shipping the fake is the single largest
unrealised return on the abstraction.

**Goal:** ship `FakeGateway` as a first-class part of the package under
`Isapp\CashierSupport\Testing`; add a `Cashier::fake()` entry point with recorded-operation
assertions (`assertSubscriptionCreated()`, `assertCharged()`, …); and export an abstract driver
conformance suite any driver can extend to prove it honours the contract.

**Scope:** `laravel-cashier-support` **only** — designed, tested and merged against this package
alone, per the monorepo's non-negotiable "support leads, drivers follow" rule. The Revolut side of
the issue (replacing its duplicated tests by extending the shared suite) is a separate, later driver
issue. The conformance suite is shipped **proven** here by having this package's own test-suite
extend it against `FakeGateway`.

### Key design facts (verified on disk)

- `FakeGateway` implements `Contracts\GatewayProvider` **directly** (not via `Gateway\BaseGateway`)
  and answers `supports()` from a hand-passed `array<Capability>` (`FakeGateway.php:120,130`). This
  hand-configured behaviour is deliberate — the counterpoint to `BaseGateway`'s method-derived
  capabilities — and is preserved.
- It depends on 4 sibling fixtures that move with it: `FakeSubscriptionBuilder`, `FakeCheckoutSession`,
  `FakeIncomingWebhook`, `FakePaymentMethodType`.
- **Assertions are recorded-operation based, not `Event::assertDispatched`-based** (Paddle's
  mechanism). With no driver installed the 9 typed domain events never fire — only drivers dispatch
  them; the package dispatches only the 2 webhook events (`WebhookController.php:99,124`). So the
  assertions scan `FakeGateway`'s own recorded operations.
- `autoload.psr-4` already maps `Isapp\CashierSupport\` → `src/`, so `src/Testing/` needs no autoload
  change. `deptrac` analyses `./src` with every class assigned to a layer, so a new `Testing` layer +
  ruleset is required.
- Precedent for shipping a test double in `src/` with phpunit as a dev/soft dependency:
  `Laravel\Paddle\CashierFake` and Laravel's own `Illuminate\...\Testing` traits.

## Functional requirements

- **FR-1** — `FakeGateway` and its 4 collaborators ship under `Isapp\CashierSupport\Testing`
  (`src/Testing/`), in production `autoload`. Behaviour unchanged (hand-passed capability set
  preserved).
- **FR-2** — `Cashier::fake(array $capabilities = []): FakeGateway` builds a `FakeGateway`, registers
  it as the active driver (`extend('fake', …)` + `cashier-support.default = 'fake'`), and returns the
  instance. **No arg ⇒ supports all `Capability::cases()`**; an explicit array constrains.
  `FakeGateway`'s constructor semantics (empty = supports nothing) are unchanged; the all-caps default
  lives only in the `fake()` helper.
- **FR-3** — `FakeGateway` records each mutating operation for later assertion: charges, refunds,
  created subscriptions (recorded in the builder's `create()`/`add()`), cancellations, customer
  create/update, checkouts. Existing spy properties (`lastPauseUntil`, `lastCheckoutRequest`, …) stay;
  recording is additive.
- **FR-4** — `FakeGateway` exposes assertions matching the issue examples, each taking an optional
  `?callable $callback` predicate over the recorded record: `assertCharged` / `assertNotCharged`,
  `assertRefunded`, `assertSubscriptionCreated` / `assertSubscriptionNotCreated`,
  `assertSubscriptionCanceled`, `assertCustomerCreated`, `assertCustomerUpdated`, `assertCheckoutCreated`.
  Backed by `PHPUnit\Framework\Assert`.
- **FR-5** — An abstract `Testing\GatewayConformanceTestCase` (extends `Orchestra\Testbench\TestCase`)
  lets any driver prove **gateway-level** contract conformance by implementing a few hooks
  (`gateway()`, `billable()`, sample inputs). It asserts only guarantees that hold for **every**
  conformant gateway, independent of how a driver couples capabilities to methods:
  - (a) `capabilities()` ⊆ `Capability::cases()`, unique, and `supports()` agrees with the
    `capabilities()` list in both directions;
  - (b) **every** `GatewayProvider` operation is *answerable* — invoking it returns its declared type
    OR throws `UnsupportedOperationException` (a `CashierException`), never a raw `Error`/`TypeError`;
  - (c) for every capability the gateway declares supported that maps to concrete methods via
    `Capability::methods()` (the method-derived capabilities), those methods actually work — return
    their declared type, do not refuse — catching a gateway that *lies* about support.

  The suite drives the gateway **directly**, so it deliberately does **NOT** assert
  `¬supports(cap) ⇒ operation throws`: that coupling is unsound because gateway-level capability
  re-assertion is *optional* (`.claude/rules/capabilities.md`) — the mandatory gate is at `Billable`.
  It therefore does not verify the timing/shape *intent* capabilities (swap/pause timings, checkout
  shapes) or the builder-setter capabilities (`Trials`/`Quantity`/`Metadata`/`Taxes`/`Discounts`);
  those are `Billable`-gated and a driver covers them through `Billable`-level tests. Signature-
  verifying drivers override `sampleWebhook()` with a valid signed payload. This non-coverage is
  stated in the class docblock.
- **FR-6** — `deptrac.yaml` gains a `Testing` layer with a ruleset; `composer.json` documents phpunit +
  orchestra/testbench under `suggest` (they stay `require-dev`).

## Acceptance criteria

- **AC-1** — With no driver package installed, a test that calls `Cashier::fake()`, creates a
  subscription through `Billable`, and calls `$fake->assertSubscriptionCreated()` passes.
- **AC-2** — After `$user->charge(1000, 'pm_x')`, `$fake->assertCharged()` passes;
  `$fake->assertCharged(fn ($c) => $c->amount === 999)` fails.
- **AC-3** — `tests/Feature/FakeGatewayConformanceTest extends Testing\GatewayConformanceTestCase`
  (FakeGateway with all capabilities) runs green — every operation answerable-by-return, and every
  method-derived declared capability honored.
- **AC-4** — Conformance is proven against three fixtures under the sound contract: the fully-capable
  FakeGateway (AC-3), a refusing `MinimalGateway` (every operation answerable via
  `UnsupportedOperationException`), and a **partial** `BaseGateway` fixture that overrides some methods
  and declares some capabilities — the realistic intermediate a real driver is, and precisely the
  shape the earlier `¬supports ⇒ throws` coupling would have false-failed.
- **AC-5** — `composer ci` green: `phpunit`, `phpstan` (level 8), `deptrac` (Testing layer assigned +
  rules satisfied, `debug:unassigned` clean), `pint`.
- **AC-6** — Every existing test importing `Tests\Fixtures\FakeGateway` (and the 4 siblings) is
  repointed to `Testing\…` and still green; `PublishesWebhooksGateway` (stays in `tests/`) updated.

## Non-goals

- No Revolut / driver edits — the Revolut dedup is a separate driver issue.
- No `Event::assertDispatched`-based assertions — impossible with no driver.
- No static `Cashier::assert*` facade pass-thrus — assertions are instance-based on the returned fake.
- No changes to the 11 event classes, the `Capability` enum, or the `GatewayProvider` contract.
- The conformance suite does **not** assert `¬supports(cap) ⇒ operation throws`, nor verify
  timing/shape intent or builder-setter capabilities — those are `Billable`-gated, and asserting them
  at the gateway level would false-fail conformant drivers (see FR-5).
