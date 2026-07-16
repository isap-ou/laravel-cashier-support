# Spec: Complete SubscriptionStatus with Stripe's two unconditional dunning states

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#22](https://github.com/isap-ou/laravel-cashier-support/issues/22)

## Context & Goal

`SubscriptionStatus` covers 6 of the 8 statuses Stripe emits. `unpaid` and `incomplete_expired`
are missing — both values verified on disk at `vendor/stripe/stripe-php/lib/Subscription.php:69,73`.

`src/Models/Subscription.php:47` casts the column to that enum. Laravel resolves enum casts through
`BackedEnum::from()`, not `tryFrom()` — verified: `HasAttributes::getEnumCastableAttributeValue():964`
→ `getEnumCaseFromValue():1314` → `from():1317`. So a `cashier_subscriptions` row that a driver wrote
as `unpaid` or `incomplete_expired` throws a **`ValueError` on read** — not "unknown status", a hard
crash. The enum's own docblock (`src/Enums/SubscriptionStatus.php:14`) claims it "Mirrors the statuses
used by laravel/cashier-stripe", which is exactly the claim these two falsify.

Both are real, load-bearing Stripe states: they are what a subscription becomes when renewal payment
keeps failing (`unpaid`), or when an initial payment is never completed (`incomplete_expired`). Any
future Stripe driver on top of this package cannot mirror a subscription through the dunning cycle.

**Goal:** the enum is complete for these two states, and they carry Stripe's access semantics from
the moment they exist — rather than being added now and given meaning later.

### Why the fix does not stop at adding two cases

`Models\Subscription::active()` (`src/Models/Subscription.php:149`) is `isActive() || onGracePeriod()`.
Both new cases yield `isActive() === false`, but an `unpaid` row with a future `ends_at` would pass
through `onGracePeriod()` and return `active() === true` — the package would keep serving a customer
whose dunning has been exhausted. Adding the cases alone therefore converts a loud crash into a
silent revenue leak, which `.claude/rules/smart-stubs.md` forbids: *"Throws" beats "quietly does
nothing", every time.*

### Why this does not collide with #25

Stripe's `active()` treats these four statuses in two distinct ways
(`vendor/laravel/cashier/src/Subscription.php:232-235`):

```php
(! Cashier::$deactivateIncomplete || $this->stripe_status !== STATUS_INCOMPLETE) &&  // toggleable -> #25
$this->stripe_status !== StripeSubscription::STATUS_INCOMPLETE_EXPIRED &&           // unconditional -> this task
(! Cashier::$deactivatePastDue || $this->stripe_status !== STATUS_PAST_DUE) &&      // toggleable -> #25
$this->stripe_status !== StripeSubscription::STATUS_UNPAID;                         // unconditional -> this task
```

`unpaid` and `incomplete_expired` are the two states Stripe denies unconditionally — there is no
policy and no opt-out, so there is nothing about them for #25 to decide. #25 owns the `active()` →
`valid()` rename and the `$deactivatePastDue` / `$deactivateIncomplete` toggles, which govern the
*other* two statuses. This task leaves `past_due` and `incomplete` untouched.

Backward-compatibility impact is nil: rows in these two states currently throw on read, so no caller
can observe today's behaviour in order to depend on it.

## Functional requirements

- **FR-1**: `SubscriptionStatus` has `Unpaid = 'unpaid'` and `IncompleteExpired = 'incomplete_expired'`.
- **FR-2**: Reading a `cashier_subscriptions` row in either state returns the enum; no `ValueError`.
- **FR-3**: Both resolve a human label through `getLabel()` (`lang/en/enums.php`).
- **FR-4**: Both report `isActive() === false`.
- **FR-5**: `SubscriptionStatus::deniesAccess()` returns `true` for exactly these two cases and
  `false` for the other six.
- **FR-6**: `Models\Subscription::active()` returns `false` for either status regardless of `ends_at`,
  mirroring Stripe's unconditional exclusion at `vendor/laravel/cashier/src/Subscription.php:233,235`.

## Acceptance criteria

- **AC-1**: Red test — a `cashier_subscriptions` row seeded with `status = 'unpaid'` and read back
  through the model returns `SubscriptionStatus::Unpaid`. Fails today with `ValueError`.
- **AC-2**: Same for `incomplete_expired` → `SubscriptionStatus::IncompleteExpired`.
- **AC-3**: `Unpaid->getLabel() === 'Unpaid'` and `IncompleteExpired->getLabel() === 'Incomplete expired'`,
  resolved from package translations (house style: `PastDue` → `'Past due'`).
- **AC-4**: Table-driven test over `SubscriptionStatus::cases()` — `deniesAccess()` is `true` for
  exactly `Unpaid` and `IncompleteExpired`, `false` for the other six; `isActive()` is `false` for both.
- **AC-5**: `active() === false` for a subscription in either status with `ends_at` in the future.
- **AC-6**: No regression — `composer ci` green (`test`, `analyse`, `deptrac`, `lint`).

## Non-goals

Binding for planning and for the §4b½ review:

- **The `active()` → `valid()` rename and the `$deactivatePastDue` / `$deactivateIncomplete`
  toggles — issue #25.** The semantics of `past_due` and `incomplete` are not touched here.
- Query scopes — issue #29.
- `tryFrom`-based graceful degradation of unknown gateway statuses. The issue raises it as an
  option; it contradicts `.claude/rules/smart-stubs.md` ("Throws beats quietly does nothing"),
  so it needs its own issue and its own decision rather than riding along here.
- Any change to the Revolut driver. `RevolutSubscriptionState::toSubscriptionStatus()`
  (`packages/isapp/laravel-cashier-revolut/src/Enums/RevolutSubscriptionState.php:25-35`) maps six
  Revolut states, none of which correspond to either new case — Revolut's `overdue` is already
  `PastDue`, and it has no dunning-exhausted or expired-incomplete state. Neither case is reachable
  through that driver.

## Edge cases

- **No `match` anywhere in either package takes `SubscriptionStatus` as its subject**, so adding
  cases cannot raise `UnhandledMatchError`. `RevolutSubscriptionState.php:27` looks like the risk
  but is exhaustive over *Revolut* states and merely returns our enum — it breaks from the opposite
  direction (adding a Revolut state), not this one.
- `status` is a plain `varchar` (`database/migrations/create_cashier_subscriptions_table.php:19`) —
  no enum-typed column and no CHECK constraint. **No migration is required.**
- The **write** path also resolves through `from()` (`HasAttributes::setEnumCastableAttribute():1301-1303`),
  so the red test cannot seed a row via `ConcreteSubscription::create(['status' => 'unpaid'])` — that
  throws on write. It must seed with a raw `DB::table(...)->insert(...)` to bypass the cast, which is
  what the issue's own repro describes.
- `EnumTest.php:28`'s `assertCount(count(...cases()))` is over `PaymentStatus`, not
  `SubscriptionStatus`, so no case-count assertion breaks.
- `lang/en/enums.php` keys labels by case name; a case with no entry does not resolve a label.
- `Models\Subscription::canceled()` (`:157`) is `=== Canceled || ends_at !== null`, so an `unpaid`
  row with `ends_at` set reports `canceled() === true`. That is pre-existing looseness in
  `canceled()`, not something these cases introduce; it stays untouched.

## Open questions

None.
