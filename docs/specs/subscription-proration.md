# Spec: Proration intent on swap and quantity — a capability, not a port

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#53](https://github.com/isap-ou/laravel-cashier-support/issues/53)

## Context & Goal

Proration is absent from the package — a grep of `src/` for `prorat|proration|create_prorations`
returns nothing, and it sits in CLAUDE.md's "Not implemented" list. #53 was split out of the closed
#37 to give it a *design* rather than a port, because the two references disagree so completely that
copying either would carve one gateway's vocabulary into a package that must not know a gateway
exists.

Read from disk, not remembered:

- **Stripe** (`vendor/laravel/cashier/src/Concerns/Prorates.php`) — wire key `proration_behavior`,
  values `{none, create_prorations (default), always_invoice}`. One axis; "invoice now" is folded in
  as the third value. Reader `prorateBehavior()` (`:68`); `noProrate()` (`:19`) sets `none`.
- **Paddle** (`vendor/laravel/cashier-paddle/src/Concerns/Prorates.php`) — wire key
  `proration_billing_mode`, values `{prorated_next_billing_period (default), full_next_billing_period,
  prorated_immediately, full_immediately, do_not_bill}`. Two axes (prorated/full × now/next-period)
  plus a `do_not_bill` opt-out; no reader — callers read the raw property. `noProrate()` (`:34`) sets
  `full_next_billing_period` — it still bills, just the *full* amount.

The two share a filename and exactly three method names (`prorate`, `noProrate`,
`setProrationBehavior`) and **agree on no value string**. `noProrate()` is a name collision meaning
opposite things (Stripe suppresses; Paddle bills full). So the design rule points away from a port:
derive the abstraction from what they *agree* on, and name each disagreement as a `Capability`.

**Goal:** an app can say whether a swap or a quantity change should be prorated; a gateway that
cannot suppress proration refuses "don't prorate" by name; and no gateway's wire vocabulary appears
in `src/`.

## The one axis the references agree on

Both express exactly one intent unambiguously: *should this change be prorated?* Beyond that they
diverge (Stripe folds "invoice now" into a third value; Paddle spreads prorated/full × now/next-period
+ `do_not_bill`). Those divergences are the Non-goals below. The agreed axis — prorate vs. not — is
the whole of what this ticket makes expressible.

### Asymmetric gating (the crux)

`Prorate` is the universal baseline both references default to, so it is **ungated** — an existing
swap that now defaults to `Prorate` must keep working on a driver that has not yet modelled proration.
Only `NoProrate` is a caller intent a gateway might silently drop, so only `NoProrate` carries a
capability.

This diverges from `SwapTiming` (where both cases map to a capability) for a stated reason:
`SwapTiming::Immediate` is genuinely unsupported by some gateways (Revolut defers), whereas no gateway
"cannot prorate by default". It satisfies `.claude/rules/capabilities.md` ("every setter is gated or
it goes silent"): a dropped `Prorate` still prorates (intent met), a dropped `NoProrate` silently
prorates against the caller's word (must be gated).

## Functional requirements

**FR-1** — `Enums\Proration` exists, a string-backed enum mirroring `Enums\SwapTiming`:
- `Prorate = 'prorate'` (the default the callers use) and `NoProrate = 'no_prorate'`.
- `capability(): ?Capability` — `Prorate => null` (ungated baseline), `NoProrate =>
  Capability::SubscriptionNoProration`. The docblock records the asymmetry and why it inverts
  SwapTiming's total mapping. No wire string from either gateway appears (`prorate` / `no_prorate` are
  neither Stripe's nor Paddle's vocabulary).

**FR-2** — `Capability::SubscriptionNoProration = 'subscription.no_proration'` is added. It gates the
"do not prorate" intent: a gateway that cannot suppress proration declares it lacks this and refuses
`NoProrate`. It is a sub-mode of `swapSubscription()`/`updateSubscriptionQuantity()` (one method,
many intents — like the swap timings), so it joins the `[]` group in `Capability::methods()` and
becomes a `declaredCapabilities()` responsibility. Documented counts move: **9 → 10 declared, 23 → 24
cases** (method-backed stays 14). Every site stating those numbers is updated: `Capability::methods()`'s
docblock, `Gateway\BaseGateway`'s docblock, and `CLAUDE.md`.

**FR-3** — `Contracts\SubscriptionOperations` takes the intent on both sites:
- `swapSubscription(..., SwapTiming $timing = SwapTiming::Immediate, Proration $proration =
  Proration::Prorate, array $options = [])` — inserted between `$timing` and `$options` (the two intent
  enums grouped, the options bag stays last).
- `updateSubscriptionQuantity(Model $billable, string $type, int $quantity, string $price, Proration
  $proration = Proration::Prorate)` — appended (no `$options` bag; a trailing optional is a clean,
  fully BC-safe add).

**FR-4** — `Gateway\Guards\GuardsSubscriptions` gates the intent at the **consumption site** of each
method: `if ($capability = $proration->capability()) { $this->ensure($capability); }`, then forwards
`$proration` to `inner()`. Added to both `swapSubscription()` (after the existing timing + tax gates)
and `updateSubscriptionQuantity()` (after `SubscriptionQuantityUpdate`).

**FR-5** — `Gateway\Defaults\RefusesSubscriptions` updates both signatures to match the contract
(adds the `Proration` param + `use`); the refusal logic is unchanged — a `BaseGateway` that refuses
the method at all still throws its base capability (swap timing / quantity-update). The proration
refusal is the guard's job (FR-4), not the default's.

**FR-6** — App-facing surfaces thread the intent through, defaulting to `Prorate`:
- `Models\Subscription::swap(string|array $prices, SwapTiming $timing = SwapTiming::Immediate,
  Proration $proration = Proration::Prorate, array $options = [])` → passes `$proration` to
  `swapSubscription()`. Docblock updated.
- `Concerns\ManagesSubscriptions`: `updateSubscriptionQuantity()`, `incrementSubscriptionQuantity()`,
  `decrementSubscriptionQuantity()` each gain a trailing `Proration $proration = Proration::Prorate`,
  threaded through the private funnel `cashierSetQuantity()` to the provider call.

**FR-7** — `Testing\FakeGateway` records the intent so a test can prove it reached the gateway: new
`?Proration $lastSwapProration` and `?Proration $lastQuantityProration` public props, set in
`swapSubscription()` / `updateSubscriptionQuantity()`; both signatures updated.

## Acceptance criteria

**AC-1** — `Proration::capability()` maps both cases correctly (unit): `Prorate → null`,
`NoProrate → Capability::SubscriptionNoProration`.

**AC-2** — On a gateway that declares `SubscriptionNoProration`, `NoProrate` on **swap** and on
**updateQuantity** reaches the gateway (asserted via `FakeGateway::$lastSwapProration` /
`$lastQuantityProration`).

**AC-3** — On a gateway that does **not** declare `SubscriptionNoProration`, requesting `NoProrate` on
swap and on quantity throws `UnsupportedOperationException` naming `subscription.no_proration`; the
default (`Prorate`) call on the same gateway succeeds (proves the asymmetric gate — proration does not
newly break an unadorned swap/quantity change).

**AC-4** — `Capability::cases()` count is 24 and `SubscriptionNoProration` is in the declared-only
(`methods() === []`) set.

## Non-goals (each a named future capability, not a flattening)

- **Invoice-now axis** — Stripe `always_invoice`, Paddle `prorated_immediately`/`full_immediately` and
  its `setProrateAndInvoice()` coercion + `LogicException`. A separate axis (proration billing
  *timing*), deferred to its own issue/capability.
- **Paddle `do_not_bill`** — a suppress-billing opt-out outside the prorate/full grid, Paddle-only. A
  future capability, not modelled here.
- **Cancel-now proration** — Stripe translates it to a boolean, Paddle sends nothing; that divergence
  gets its own decision. Out of scope for this pass.
- **Proration on subscription *creation*** (`SubscriptionBuilder`) — the issue names swap/quantity/
  cancel, not create. Out of scope.
- **Any driver implementation.** Support-first: drivers take the new signatures in their own
  coordinated release. Note for the driver issue: `swapSubscription`'s `$options` bag is a back door —
  per `capabilities.md` the driver must reject a smuggled proration key, or the support gate is
  decoration. Support cannot close a driver's bag; this is recorded, not done here.

## Edge cases

- **Default swap/quantity is unchanged behaviour.** With `Prorate` ungated, adding the parameter does
  not newly gate any existing call — a driver that never declares `SubscriptionNoProration` still
  serves every default swap and quantity change. Recorded so it is a decision, not a discovery.
- **`NoProrate` semantics stay below the abstraction.** The caller says "don't prorate"; what a
  gateway does with that (Stripe suppresses line items, Paddle bills the full amount) is the gateway's,
  faithfully — the abstraction carries the intent, not the mechanism. This is exactly why the
  `noProrate()` name collision must not be ported.

## Open questions

None outstanding — the two design decisions (minimal one-axis shape; swap + quantity sites only) were
settled with the maintainer before approval.
