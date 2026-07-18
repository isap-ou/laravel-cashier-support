# Spec: Remove pause-at-period-end — pause becomes single-intent

Status: Approved

Issue: [isap-ou/laravel-cashier-support#72](https://github.com/isap-ou/laravel-cashier-support/issues/72)

Supersedes parts of: [`docs/specs/subscription-pause-timing.md`](subscription-pause-timing.md) (#30)

## Context & Goal

Research established that pause-at-period-end exists **only in Paddle** (a *reference*, never a
shipped driver). Stripe is immediate-only (`pause_collection`); no shipped driver
(Revolut/Braintree/Adyen) implements deferred pause or ever will. Issue #72 (approved owner
decision) removes `Capability::SubscriptionPauseAtPeriodEnd`, consciously overriding the
"Stripe/Paddle divergence → `Capability`" rule for this one case because the divergence is
reference-only with no driver path.

With only one pause intent remaining, pause is modeled like **`resume`** — a single operation
gated by a single capability, with **no timing enum** (Option B). The correct structural parallel
is `resumeSubscription()`/`SubscriptionResume`, **not** `swapSubscription()`/`SwapTiming` (swap
keeps two timings because a real driver backs `SwapAtPeriodEnd`). Pause *state* (`paused_at`,
`resumes_at`, `TracksPause`) is orthogonal to pause *timing* and is untouched.

## Non-goals

- **No** change to `SwapTiming` or the swap capabilities (driver-backed).
- **No** rename of `SubscriptionPauseImmediate` → `SubscriptionPause` — the `.immediate` value is a
  declared/wire string; that rename is its own separate BC decision, out of scope (#72 keeps the name).
- **No** change to pause STATE: `paused_at`/`resumes_at` columns, casts, `Models\Concerns\TracksPause`,
  `DTO\Subscription::$pausedAt/$resumesAt`, migrations.
- **No** change to `resumeSubscription()`, nor to the `?DateTimeInterface $until` auto-resume param
  (orthogonal to timing).
- **No** edit to `.claude/rules/capabilities.md` (its worked example is swap) or `CHANGELOG.md` history.
- Driver packages are not opened, not grepped, not fixed here (monorepo rule).

## Functional requirements

- **FR-1** — Delete `src/Enums/PauseTiming.php` entirely (public type removed — BC break).
- **FR-2** — Remove `Capability::SubscriptionPauseAtPeriodEnd` (the case + its entry in the
  `methods()` `[]`-group).
- **FR-3** — Move `SubscriptionPauseImmediate` into the `methods()` map:
  `self::SubscriptionPauseImmediate => ['pauseSubscription']`, mirroring `SubscriptionResume`.
  Rewrite the pause comment at `Capability.php:26-29` (the "timing *is* the pause" rationale is gone)
  and drop the pause bullet from the `methods()` docblock exceptions list.
- **FR-4** — Drop the `PauseTiming $timing` parameter from `pauseSubscription()` in all four sites,
  and drop each `PauseTiming` import:
  - `Contracts\SubscriptionOperations` →
    `pauseSubscription(Model $billable, string $type = 'default', ?DateTimeInterface $until = null): Subscription;`
  - `Gateway\Defaults\RefusesSubscriptions` →
    `throw UnsupportedOperationException::forCapability(Capability::SubscriptionPauseImmediate)`
    (drop the "names the timing" docblock note; swap's stays).
  - `Gateway\Guards\GuardsSubscriptions` → gate `$this->ensure(Capability::SubscriptionPauseImmediate)`.
  - `Models\Subscription::pause()` → `pause(?DateTimeInterface $until = null): static`, delegating
    `pauseSubscription($owner, $this->type, $until)`.
- **FR-5** — `Testing\FakeGateway`: drop the `PauseTiming` import, `$lastPauseTiming` property + its
  recording, and `$timing` from the signature. Keep the fact-of-pause / `$until` recording and any
  assertion helper.
- **FR-6** — Update `BaseGateway` docblock: pause moves out of the "cannot be read off the code" list;
  fix any counts it cites.
- **FR-7** — **Recount** method-backed vs declared tallies against the actual code
  (`Capability::cases()` + `methods()`), update every doc citing them (`Capability::methods()`
  docblock, `BaseGateway` docblock, `CLAUDE.md` "Known divergences" + "Capability system" block,
  remove its `PauseTiming::AtPeriodEnd` examples). Target to **verify, not trust**: 14 method-backed /
  9 declared / 23 cases (9 declared = swap 2 + checkout 2 + builder-setters 4 + Discounts 1).
- **FR-8** — Docs: README pause section (drop `PauseTiming`; `pause()` now takes only optional
  `$until`); `CLAUDE.md` reference-API pause line; add a "Superseded by #72" banner to
  `docs/specs/subscription-pause-timing.md` (FR-1/FR-2/FR-5, AC-2/AC-4 there no longer hold) — leave
  the rest as the #30 point-in-time record.

## Acceptance criteria

- **AC-1** — `grep -r PauseTiming src/ tests/` returns nothing (bar the superseded-spec banner);
  `composer analyse` green.
- **AC-2** — `Capability::SubscriptionPauseAtPeriodEnd` gone; `SubscriptionPauseImmediate` +
  `SubscriptionResume` present (unit test on the case set).
- **AC-3** — A gateway overriding `pauseSubscription()` reports
  `supports(SubscriptionPauseImmediate) === true` purely off the code; one that doesn't reports false
  — exactly as resume (BaseGateway `supports()` / conformance test).
- **AC-4** — Pause on an unsupporting gateway throws `UnsupportedOperationException` naming
  `SubscriptionPauseImmediate` (replaces the old "refused for a timing" test).
- **AC-5** — `pause($until)` forwards `$until` unchanged; pause-state predicates/scopes (`paused()`,
  `onPausedGracePeriod()`, `TracksPause`) unchanged and green.
- **AC-6** — `composer ci` green (phpunit + phpstan L8 + deptrac + pint); `ExceptionBoundaryTest`
  resolves every `@throws` on the changed signatures.

## Edge cases / risks

- Method-count bookkeeping historically miscounted (#37/#38) → recount from code (FR-7).
- Driver fallout (a driver overriding the old `pauseSubscription` signature) is **expected**, is the
  driver's own coordinated-release issue, and is **not** a reason to soften this change.
- `Cashier::fake()` (`Testing\FakeGateway`) ships in production autoload — its signature change is
  intentionally part of the public surface.
