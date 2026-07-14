---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Capabilities — gate the intent, not the operation

## A capability gates what the CALLER meant

A boolean flag over an operation is not enough when two gateways do the "same" operation
with semantics an app cannot ignore. Stripe and Paddle swap a plan immediately; Revolut
only schedules it for the end of the billing cycle. "Supports swap" was true for both — so
the app could not ask the question, and branched on the driver name instead. That is the
coupling this package exists to remove.

So the caller states its intent and the gate answers:

- `SwapTiming::Immediate` / `AtPeriodEnd` → `SubscriptionSwapImmediate` /
  `SubscriptionSwapAtPeriodEnd`
- the SHAPE of a `DTO\CheckoutRequest` (`forPrices()` / `forAmount()`) → `CheckoutPrices` /
  `CheckoutAmount`

**Do not** build a descriptor object (`swapCapability()->timing`). It only lets the app
write `if ($cap->timing === Immediate)`, which is the driver-name branch with a type on it.

The default must be the unsurprising one — `Immediate`, because that is what Stripe and
Paddle do — so a gateway that can only defer is forced to say so, rather than quietly
giving the caller a change that lands next month.

## Every setter is gated, or it goes silent

An ungated setter does not fail. It accepts the call and the value goes nowhere: a trial
that does not trial, a quantity that is not billed, tax rates that are discarded, metadata
a gateway has nowhere to put. Four separate capabilities shipped ungated before this was
written down (`Taxes`, `SubscriptionTrials`, `SubscriptionQuantity`, `SubscriptionMetadata`),
and each failed the same way — in silence, with the app's data on the floor.

When adding anything to `Contracts\SubscriptionBuilder` or a `Concerns\*` trait: gate it
in `Builders\GuardedSubscriptionBuilder` or the concern, at the point the value is
CONSUMED — not merely where it is declared. Tax rates are read when a subscription is
built *and when it is swapped*; guarding only the first left the hole open.

## The gate lives here, not in the driver

A driver cannot forget to declare something unsupported, because the check is not its to
make. A driver may re-assert it (a direct provider call bypasses `Billable`), and should
where money or state is at stake — but the support gate is the one that must exist.

## And the driver's escape hatch is not a back door

`$options` passthroughs re-introduce exactly the field the gate just refused. Every guard
added here needs the matching `$options` key closed in the driver, or the throw is
decoration.
