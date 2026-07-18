# Spec: Pause with a timing, and somewhere for the paused state to live

Status: Implemented (pause-timing half superseded by #72)

Issue: [isap-ou/laravel-cashier-support#30](https://github.com/isap-ou/laravel-cashier-support/issues/30)

> **Superseded in part by [#72](https://github.com/isap-ou/laravel-cashier-support/issues/72):**
> pause-at-period-end existed only in Paddle (a reference, no driver), so it was removed. Pause is
> now single-intent — `Enums\PauseTiming` is gone, `pauseSubscription()`/`pause()` take no timing,
> and `SubscriptionPauseImmediate` is read off the code like resume. **FR-1, FR-2, FR-5 and AC-2,
> AC-4 below no longer hold.** The paused-**state** half (FR-3/FR-4/FR-6, `paused_at`/`resumes_at`,
> `TracksPause`, `DTO\Subscription` fields, AC-1/AC-3) is unchanged. See
> [`remove-pause-at-period-end.md`](remove-pause-at-period-end.md). This document is left as the
> point-in-time #30 record.

> **Later change (#39):** the app-facing pause moved off `Billable` onto the model —
> `$user->subscription('default')->pause(PauseTiming::…)` — and the capability check moved to the
> `Gateway\GuardedProvider` boundary. The timing mechanism this spec describes (the two
> `SubscriptionPause*` capabilities, `$timing->capability()`, `SubscriptionOperations::pauseSubscription()`)
> is unchanged; only the call site did. This document is left as the point-in-time #30 record.

## Context & Goal

`Paused` exists three times in the package and has nowhere to be stored. It is a capability
(`Capability::SubscriptionPause`, `src/Enums/Capability.php:26`), a method
(`Concerns\ManagesSubscriptions::pauseSubscription()`, `:179`) and a status
(`SubscriptionStatus::Paused`, `src/Enums/SubscriptionStatus.php:28`) — but there is no
`paused_at` column, `pauseSubscription()` takes no timing, and there is no way to say *when*
the pause should take effect.

That leaves a driver two choices, both wrong. It can write `Paused` the moment the app calls —
revoking access on the click, which is not what a scheduled pause means — or it can write
nothing and lose the request entirely. The swap surface already solved exactly this shape with
`Enums\SwapTiming` and the `next_price` / `next_price_starts_at` columns: the caller states a
timing, the gate answers, and a deferred change has a column to live in until it lands. Pause
needs the same treatment.

**Goal:** an app can say whether it wants the pause now or at period end; a gateway that cannot
honour that timing refuses by name; and a pause scheduled for period end leaves the subscription
usable until `paused_at`.

### The references, read from disk (not remembered)

Stripe and Paddle disagree here, and per the design rule that disagreement is what a
`Capability` is for.

- **Paddle** (`vendor/laravel/cashier-paddle/src/Subscription.php`) does both timings.
  `pause(bool $pauseNow = false)` (`:734`) sends `effective_from: next_billing_period` by
  default and `immediately` when `$pauseNow`. It **defers by default** — the bare verb is the
  deferred one, `pauseNow()` (`:769`) is immediate, mirroring `cancel()` / `cancelNow()`. It
  persists one column, `paused_at` (`:741`), writing two different sources into it: the real
  `paused_at` for an immediate pause, and `scheduled_change.effective_at` for a deferred one.
  `onPausedGracePeriod()` (`:346`) then discriminates purely by tense —
  `$this->paused_at && $this->paused_at->isFuture()`.
- **Stripe** (`vendor/laravel/cashier`) does not wrap pausing at all — zero matches for `pause`
  across the package. Its raw API has two *different* concepts, confirmed against the official
  docs (docs.stripe.com/billing/subscriptions/pause-payment and /api/subscriptions/object):
  - `pause_collection` is **immediate-only** — the docs describe no period-end scheduling —
    and it explicitly leaves the status unchanged: *"the subscription status will be unchanged
    and will not be updated to `paused`."* It carries a readable `resumes_at`.
  - `status = paused` is a *different* thing: a subscription reaches it *"only when a trial
    ends without a payment method"*. It is not the result of a pause request.

So the timings genuinely diverge — Paddle both (defaulting to deferred), Stripe immediate-only —
which is why the capability splits rather than staying a single flag.

### Why `paused_at` is "pause effective at", not "paused since"

The column names the instant the pause takes (or took) effect, and tense disambiguates: a
`paused_at` in the past means the subscription is actually paused now; a `paused_at` in the
future means the pause is scheduled and the subscription is still serving. This is Paddle's
model exactly, and it is why `paused()` and `onPausedGracePeriod()` both read this one column
and split on `isPast()` / `isFuture()` — the same shape `TracksCancellation` already uses for
`ends_at` (`hasEnded()` vs `onGracePeriod()`).

### Why `active()` / `valid()` do not change — the issue's premise corrected

The issue states that `active()` returning `false` for `Paused` means "the paused grace period
is simply gone". That is not how the reference behaves and not how ours needs to. Paddle's
`valid()` / `active()` never read `paused_at` (`Subscription.php:172`, `:251`); during a
scheduled pause the status stays `active` and only `recurring()` (`:272`) subtracts the grace
period. Our positive-list `active()` is already correct: a driver holding the status at `Active`
while `paused_at` is in the future yields a subscription that is `valid()` and `active()` right
up to `paused_at`, which is precisely AC-1. `Models\Concerns\DecidesAccess` is therefore a
non-goal.

## Functional requirements

**FR-1** — `Enums\PauseTiming` exists, a string-backed enum mirroring `Enums\SwapTiming`:
`Immediate = 'immediate'` and `AtPeriodEnd = 'at_period_end'`, with
`capability(): Capability` mapping each case to its capability. **The default the callers use is
`AtPeriodEnd`**, which inverts `SwapTiming::Immediate`; the enum's docblock records why (Paddle
defers by default, Stripe has no deferred pause to copy, and the `…Now` convention makes the
bare verb the deferred one).

**FR-2** — `Capability::SubscriptionPause` splits into `SubscriptionPauseImmediate`
(`subscription.pause.immediate`) and `SubscriptionPauseAtPeriodEnd`
(`subscription.pause.at_period_end`), mirroring the swap split. `pauseSubscription` leaves the
`Capability::methods()` map — one method cannot stand behind two intents — so both cases join the
`[]` group and become `declaredCapabilities()` responsibilities. The documented counts move:
14 → 13 method-backed, 8 → 10 declared, 22 → 23 cases. Every place that states those numbers is
updated: `Capability::methods()`'s docblock, `Gateway\BaseGateway`'s "these eight", and
`CLAUDE.md`.

**FR-3** — `cashier_subscriptions` gains `paused_at` and `resumes_at`, both nullable timestamps,
in the existing create migration (there are no installations to migrate forward). `resumes_at`
takes Stripe's field name per `constraints.md`. `Models\Subscription` casts both to
`immutable_datetime`.

**FR-4** — `Models\Concerns\TracksPause` is composed into `Models\Subscription`, modelled on
`TracksCancellation`:
- `paused()` — `paused_at !== null && paused_at->isPast()`
- `onPausedGracePeriod()` — `paused_at !== null && paused_at->isFuture()`
- `scopePaused` / `scopeOnPausedGracePeriod` — null-explicit (`whereNotNull` before any bare
  `<` / `>`), per the rule at `TracksCancellation:143-151`
- `scopeNotPaused` / `scopeNotOnPausedGracePeriod` — derived via `whereNot`, never hand-mirrored

**FR-5** — `pauseSubscription()` gains `PauseTiming $timing = PauseTiming::AtPeriodEnd` and
`?DateTimeInterface $until = null`, across the contract (`Contracts\SubscriptionOperations`), the
refusal (`Gateway\Defaults\RefusesSubscriptions`) and the Billable concern
(`Concerns\ManagesSubscriptions`). The concern gates on `$timing->capability()`, mirroring
`swapSubscription()`. The refusal names the timing via `$timing->capability()` (not a ternary
that re-derives the mapping the enum owns).

**FR-6** — `DTO\Subscription` gains `?CarbonImmutable $pausedAt` and `?CarbonImmutable $resumesAt`,
following the `pendingPrice` / `pendingPriceStartsAt` precedent, so a driver can report the paused
state back.

## Acceptance criteria

**AC-1** — A pause scheduled for period end leaves the subscription usable until `paused_at`. A
row with status `Active` and a future `paused_at` returns `valid() === true`,
`onPausedGracePeriod() === true` and `paused() === false`. (The issue's stated criterion.)

**AC-2** — Asking for `PauseTiming::Immediate` on a gateway that declares only
`SubscriptionPauseAtPeriodEnd` throws `UnsupportedOperationException`, and the default call
(no timing) resolves to `AtPeriodEnd`. The driver-level refusal names the timing the caller
asked for, not "pause".

**AC-3** — Each new predicate agrees with its scope across the full fixture matrix (the
`SubscriptionScopeTest` space, extended with a `paused_at` axis), including the derived negations.

**AC-4** — `PauseTiming::capability()` maps both cases correctly (unit).

## Non-goals

- `Models\Concerns\DecidesAccess`, `active()`, `valid()` — unchanged, for the reason above.
- `recurring()` / `scopeRecurring` — still deliberately absent (#60); pause does not add it.
- Resume-side changes — `resumeSubscription()` keeps its current signature. Cancelling a
  *pending* pause (Paddle's `PATCH scheduled_change: null`) is a driver concern and out of scope.
- Any driver implementation. This is a support-package contract change; drivers that override
  `pauseSubscription` take the signature in their own coordinated release (a breaking change,
  per `CLAUDE.md`).
- Splitting `SubscriptionStatus::Paused` into the two concepts Stripe distinguishes
  (pause-request vs trial-ended-no-payment-method) — documented here, deferred to its own issue.

## Edge cases

- **A paused subscription is "served" per-gateway, and that is intended.** With `paused()`
  reading `paused_at` and `DecidesAccess` untouched, a Stripe-style pause (status stays `Active`,
  `paused_at` in the past) yields `paused() === true` **and** `active() === true`, because
  Stripe's `pause_collection` genuinely keeps the subscription active. A Paddle-style pause
  (status becomes `Paused`, `paused_at` in the past) yields `paused() === true` and
  `active() === false`. Each is faithful to its gateway; the difference is surfaced rather than
  hidden, which is what the capability system is for. It is recorded here so it is a decision,
  not a discovery.
- **Null `paused_at`** is "no pause", full stop — both predicates are false and both negation
  scopes include the row. The null-explicit scope construction is what keeps the negations from
  silently dropping null rows (SQL `NOT UNKNOWN` is `UNKNOWN`); the extended matrix pins it.
- **`resumes_at` without `paused_at`** is a driver defect, not a state this package interprets.
  Only Stripe exposes `resumes_at` as readable state; Paddle accepts it inbound only. The column
  exists so the concept has a home under Stripe's name, not because every driver fills it.

## Open questions

None outstanding — the four design decisions (split, default timing, `active()` untouched,
`paused()` reads `paused_at`) were settled before approval.
