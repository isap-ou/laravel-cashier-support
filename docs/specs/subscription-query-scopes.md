# Spec: Query scopes for Models\Subscription

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#29](https://github.com/isap-ou/laravel-cashier-support/issues/29)

## Context & Goal

`src/Models/Subscription.php` has no query scopes. Every predicate is computed in PHP after
hydration (`:160-322`), and `Concerns\ManagesSubscriptions` reads them one record at a time
(`:83`, `:119`). Two things follow, and both are the same missing layer:

- `User::whereHas('subscriptions', fn ($q) => $q->active())` cannot be written at all — a
  scope is the only way to reach inside a relationship-existence query, and there is none.
- A dunning cron that wants every `past_due` subscription must hydrate the entire table to
  find them, because the only thing that knows what `past_due` means is a PHP method on a
  loaded model.

The references ship 17 scopes between them (`vendor/laravel/cashier/src/Subscription.php`,
`vendor/laravel/cashier-paddle/src/Subscription.php`). The logic is provider-neutral: it reads
`status`, `ends_at` and `trial_ends_at`, all columns we already own. This is not a missing
abstraction — it is a layer that was never ported.

Blocked on #22 (the enum had to be complete first) and #25 (the `active()`/`valid()` rename
decides what several of these are even called). Both are closed, so this is unblocked.

**Goal:** every predicate on the model is also expressible as a query, and the two answer
identically for every state a row can be in.

### Why this is not a transcription of the reference's scopes

Two of our predicates have no reference equivalent, and neither reference's body transfers:

- `onTrial()` (`:299-306`) falls back to the *status* when `trial_ends_at` is null. Stripe's
  is a bare date read (`:365`), so its `scopeOnTrial` (`:377`) is a bare date query.
- `canceled()` (`:290-293`) has a status arm — `status === Canceled || ends_at !== null`.
  Stripe's is `! is_null($this->ends_at)` and nothing else (`:305`).

Both of ours need an `OR` that Stripe's never did, which is what makes FR-3 below load-bearing
rather than cosmetic.

## Functional requirements

**FR-1** — `Models\Subscription` exposes twelve scopes: `valid`, `active`, `pastDue`,
`incomplete`, `canceled`/`notCanceled`, `onTrial`/`notOnTrial`, `onGracePeriod`/`notOnGracePeriod`,
`ended`/`notEnded`.

Named with the `scopeXxx` prefix rather than the `#[Scope]` attribute. The attribute exists in
the installed framework (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Scope.php`)
but not in Laravel 11, which `composer.json:20-27` still supports — under 11 the attribute is
inert and the method silently stops being a scope. The prefix is resolved by
`Model::hasNamedScope()` (`Model.php:1954`) on every supported version.

**FR-2** — Each scope selects exactly the rows whose matching predicate returns `true`.

The four negations ship without a matching predicate on purpose. PHP has `!`; a query builder
does not, and a scope cannot be negated from outside — so `notOnTrial()` is the only way to
express the complement inside a `whereHas` group. This is the one place where the scope surface
is legitimately wider than the predicate surface.

**FR-3** — A scope groups the arms *inside its own body* whenever that body mixes `AND` with
`OR`. It does **not** wrap itself against its caller.

An earlier draft of this spec required the opposite, on the claim that both references leak — a
bare `orWhere` chained after an outer condition escaping into the caller's query. **That claim
is false and the requirement was corrected before implementation.** Eloquent isolates a scope's
constraints itself: `Builder::callScope()` counts the wheres before and after the body and hands
the new ones to `addNewWheresWithinGroup()`, which rebuilds them as an isolated nested group.
Verified by compiling Stripe's ungrouped `scopeNotOnTrial` body (`:396`) verbatim and invoking it
as a scope after another condition:

```sql
-- Stripe's body, ungrouped, invoked as a scope — Eloquent parenthesises it anyway:
select * from "cashier_subscriptions" where "status" = ? and ("trial_ends_at" is null or "trial_ends_at" <= ?)
```

So Stripe `scopeNotOnTrial:396`, `scopeNotOnGracePeriod:428` and Paddle `scopeValid:183` are all
correct as written, and a scope of ours needs no self-wrapper either.

What `callScope` does **not** do is parenthesise `AND` against `OR` *within* one body. Two scopes
have both, and only they carry groups:

- `scopeValid` — `notEnded AND status NOT IN (denied) AND (grants OR onTrial OR onGracePeriod)`.
  Flattened, the trailing `OR`s rebind past both guards and hand access to the very rows #22
  exists to refuse. This is the only group in the package that decides access.
- `scopeOnTrial` — `(date AND future) OR (no date AND trialing)`.

The rest are single-operator chains, written flat like the references'.

**FR-3b — the four negations are derived from their positive twin, not mirrored.**
`scopeNotOnTrial` is `whereNot(fn ($q) => $q->onTrial())`, and likewise for `notCanceled`,
`notOnGracePeriod`, `notEnded`. Hand-writing De Morgan's is the same two-bodies-drift FR-5 kills
for the status lists: verified by dropping `scopeOnTrial`'s status arm, which fails **both**
`onTrial` and `notOnTrial` in the matrix — where a hand-written complement would have kept its
own now-inconsistent body and failed only the first.

Sound only because every positive scope is null-**explicit**: each comparison sits behind
`whereNotNull`/`whereNull`, so no NULL reaches a bare `<`/`>`. In SQL a comparison against NULL
is UNKNOWN and `NOT UNKNOWN` is UNKNOWN — so a positive scope that compared a nullable column
directly would lose its NULL rows from both itself and its negation. The 72-row matrix covers
every NULL combination, so this is pinned rather than argued.

Verified that a scope may call another scope inside a nested closure:
`Eloquent\Builder::where(Closure)` passes a full **Eloquent** builder
(`$column($query = $this->model->newQueryWithoutRelationships())`) and folds the result in via
`addNestedWhereQuery`.

**FR-4** — `scopeValid` and `scopeActive` read `Cashier::deactivatesPastDue()` and
`Cashier::deactivatesIncomplete()` at query-build time, as `statusGrantsAccess()` (`:210-217`)
does at read time.

Paddle's `valid()` gates past-due behind the same toggle its `scopeValid` reads (`:183`). A
scope that ignored the toggle would disagree with its predicate on exactly the fixture set this
issue's acceptance criterion compares.

**FR-5** — The status sets are derived from the enum, not hand-written.

`statusGrantsAccess()` is a `match` over `SubscriptionStatus`. The policy moves into one
parameterised body that both callers reach:

```php
private function statusGrants(SubscriptionStatus $status): bool  // THE match — the only body that decides
private function statusGrantsAccess(): bool                      // $this->statusGrants($this->status)
private function statusesGrantingAccess(): array                 // array_filter(cases(), $this->statusGrants(...))
```

The scope then reads `whereIn('status', $this->statusesGrantingAccess())` — predicate and scope
agree **by construction**, from one body, rather than via two lists that drift.

The parameter is what makes it free. An earlier version had the predicate ask the *list* and
search it (`in_array($this->status, $this->statusesGrantingAccess(), true)`), which allocated and
filtered an eight-element array on every `valid()` — on the predicate whose neighbouring split
exists precisely to avoid evaluating `hasEnded()` twice. The scope gets its list; the hot path
keeps its single `match` jump.

This is the defect the references demonstrate. Stripe's `active()` (`:229-236`) and
`scopeActive()` (`:240-256`) are two independently maintained bodies, and both are status-*negative*
— "not one of these four bad statuses". That is only correct while the enum has no other bad
status, and ours does: `Paused` would be reported active by both, on the strength of not being
listed. A positive list read off the enum cannot fail that way.

Kept as private *instance* methods, not statics: scopes are instance methods
(`Model::callNamedScope()` → `$this->{'scope'.ucfirst($scope)}(...)`, `Model.php:1971`), so a
scope reaches them through `$this` like everything else on the model.

**FR-6** — `database/migrations/create_cashier_subscriptions_table.php` carries a composite
index on `(owner_type, owner_id, provider, status)`.

The columns `Concerns\ManagesSubscriptions::subscriptions()` (`:47-54`) actually filters, in
filter order. #29 asks for `(owner_type, owner_id, status)` — Stripe's shape
(`2019_05_03_000002:26` indexes `(user_id, stripe_status)`) — but every scope query arrives
through `subscriptions()`, which always has `provider` in its `WHERE` clause; an index that
skips it stops being useful after `owner_id`.

Written into the create migration rather than a new `extend_*` one: the package has zero
installations (`CHANGELOG.md` records that it was never published to a consumer and that 1.0.0
is the first release), so there is nothing to migrate *from*.

## Acceptance criteria

**AC-1** *(the issue's criterion, exhaustively)* — `tests/Feature/SubscriptionScopeTest.php`
seeds the cross-product of all 8 `SubscriptionStatus` cases × `ends_at` ∈ {null, past, future}
× `trial_ends_at` ∈ {null, past, future} — 72 rows. For each of the 12 scopes, the set of ids
the scope returns equals the set of ids of rows whose predicate answers `true`. The issue asks
that "both agree on the same fixture set"; this is that, over the whole space rather than a
sample.

**AC-2** — AC-1 holds with `keepPastDueSubscriptionsActive()` and
`keepIncompleteSubscriptionsActive()` both flipped. `valid` and `active` still agree with their
predicates.

**AC-3** *(the one group Eloquent does not supply)* — `scopeValid()` returns no `unpaid` or
`incomplete_expired` row, whatever date it carries. Flattening the scope's internal `OR` rebinds
it past the `deniesAccess()` guard, and these two statuses are where that shows.

Not a leak test. A test that chained two scopes and checked the caller's condition survived would
be asserting `callScope`'s behaviour, not ours, and could not fail — which is the same standard
`assertNotCount(0, …)` / `assertNotCount(72, …)` applies to every scope in AC-1.

**AC-4** *(the issue's motivating case)* — `User::whereHas('subscriptions', fn ($q) => $q->active())`
returns exactly the users whose subscription is active.

**AC-5** — `tests/Feature/MigrationsTest.php` asserts the composite index exists on
`cashier_subscriptions` after migration, with its columns **in filter order** — the same four
columns permuted would satisfy a membership check while being unusable to the query the index
exists for. Asserted on columns rather than on a name: the name is Laravel's to generate, and a
test that pinned it would fail on a rename that broke nothing. A second test reads every index
name in the schema and asserts it clears MySQL's 64-character identifier limit, which SQLite will
never report on its own.

**AC-7** — The entry points CLAUDE.md documents for reaching a scope run as written
(`test_the_documented_entry_points_reach_the_scopes`). Added after review: this change first
documented `Subscription::query()->pastDue()`, which is a fatal — the model is abstract — and
#38 is open because that file has described a non-existent API before. A snippet is a claim; this
makes it one the build checks.

**AC-6** — `composer ci` green: phpunit, PHPStan level 8, deptrac, pint.

## Non-goals

- **`scopeRecurring`** — its predicate does not exist here, and the references disagree on the
  body (#60). A scope for a predicate we have not decided is a scope for nothing.
- **`scopePaused` / `scopeNotPaused` / `scopeOnPausedGracePeriod`** — Paddle-only, and
  `onPausedGracePeriod` reads a `paused_at` column we do not have (#30).
- **`scopeExpiredTrial` / `hasExpiredTrial()`** — no such predicate here. Adding one is a new
  predicate, which is #25/#37 territory, not a port of the scope layer.
- **Any mutator on the model** — #39 is undecided and is the user's call.
- **Dropping the `morphs()` index** that FR-6's index now prefixes. Removing it means
  hand-rolling the morph columns and pinning `owner_id` to `unsignedBigInteger`, which
  constrains host apps; the redundancy is cheaper than the constraint.
- **Item-based predicates** (`hasPrice`, `hasSinglePrice`, `hasMultiplePrices`) — neither
  reference scopes them, and they are `whereHas` territory rather than a column read.
- **Billable-level query helpers** — nothing is added to `Concerns\ManagesSubscriptions`.

## Edge cases

- **`ends_at` exactly `now`.** `isPast()` and `isFuture()` are both strict, so such a row is
  neither `ended` nor `onGracePeriod`. Scopes use strict `<` and `>` to match. Stripe's
  `scopeEnded` composes `canceled()->notOnGracePeriod()` and lands on `<=`, diverging from its
  own predicate at that instant; we do not inherit that.
- **`whereIn('status', [])`** cannot arise: `statusesGrantingAccess()` always contains `Active`
  and `Trialing`, which no toggle can remove.
- **Enum binding.** `where('status', SubscriptionStatus::Active)` and `whereIn` both bind
  correctly — `Query\Builder::castBinding()` resolves through `enum_value()`, mapped over array
  bindings.
- **`notCanceled` is De Morgan's, not a negated column.** `canceled()` is
  `status === Canceled || ends_at !== null`, so its complement is
  `status != Canceled && ends_at IS NULL` — not `where('status', '!=', Canceled)` alone.
- **Index name length.** Laravel generates
  `cashier_subscriptions_owner_type_owner_id_provider_status_index` — 63 characters against
  MySQL's 64-character identifier limit, so it fits, with one character to spare. Left to the
  default and covered by a test that reads the name from the schema, since one character of
  headroom is not something to leave to a comment.

  The residual risk is stated rather than designed around: `Blueprint::createIndexName()`
  prepends the connection's table prefix when `prefix_indexes` is set — the default in Laravel's
  shipped `config/database.php` — so a host app with a non-empty `DB_PREFIX` overflows the limit
  on first migrate. An explicit name is immune, being used verbatim, and is the fix if it is ever
  reported. Testbench runs unprefixed and cannot see it.
- **SQLite and MySQL.** Tests run on SQLite under testbench; the index definition and the
  datetime comparisons must hold on both.

## Amendments after approval

**FR-3 and AC-3 were rewritten during implementation.** Both rested on the claim that the
references' scopes leak a bare `orWhere` into the caller's query, and that our scopes must wrap
themselves to avoid it. The claim was drawn from reading the reference source without checking
what Eloquent does with it, and it is wrong — `Builder::callScope()` groups a scope's constraints
automatically. The correction is recorded above rather than quietly applied, because a spec that
justifies a design with a defect that does not exist is worth less than no justification: the
next reader would have inherited the false claim as the reason not to touch the wrappers.

What survived is narrower and real — the AND/OR mix inside `scopeValid` and `scopeOnTrial` — and
the other scopes lost the redundant wrappers the false premise had put on them.

**A review pass then changed four more things**, recorded here for the same reason:

- **FR-3b** (negations derived via `whereNot` rather than hand-written De Morgan's) is new. The
  hand-written inverses were the exact defect FR-5 exists to prevent, reintroduced two files
  away from it.
- **FR-5's shape changed** — the policy is now a parameterised `statusGrants(SubscriptionStatus)`.
  The first version made the predicate search a freshly-built array, i.e. paid for the scope's
  convenience on the hottest read path, while its own docblock argued against a second
  `hasEnded()` call.
- **AC-5 gained the identifier-limit sweep** — its first version measured a string literal
  copied into the test and would have survived any rename of the index it guards.
- **AC-7 is new** — see above.

The pattern across all five corrections is one thing: a claim about code, believed because it
read well, that nobody had run. `composer ci` was green for every one of them.

## Open questions

None. The scope set, the index shape, the trait structure, and the instance-vs-static question
were all decided before approval.
