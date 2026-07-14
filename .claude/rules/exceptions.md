---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Exceptions — which failures an app is expected to catch

The line is the one Stripe and Paddle Cashier draw, and it is not arbitrary:

- A **billing failure** is a fact about the world: the card was declined, the gateway
  cannot pause, the customer does not exist, the API is down. The app cannot prevent it,
  so it must be able to catch it. Every one of these extends `CashierException`, in this
  package **and in every driver**. A driver that raises a bare exception for a billing
  failure breaks `catch (CashierException)` and is a defect.
- A **malformed argument** is a programmer error: swapping to no price at all, checking
  out a negative amount, an empty items map. It raises SPL's `InvalidArgumentException`
  and is meant to be fixed, not caught. The reference does the same — `laravel/cashier`'s
  `Subscription::swap()`: *"Please provide at least one price when swapping."*

Dressing a bad argument up as a typed failure (a `SubscriptionUpdateFailure` for an empty
price) invites an app to `catch` — and swallow — its own bug.

## The rules that keep it true

- **Every gateway operation on every contract declares what it throws.** Silence in a
  contract is what let a driver invent its own exception in the first place. This includes
  `SubscriptionBuilder` and `WebhookHandler`, not just the `*Operations` interfaces.
- **A declared guard must exist in code.** `charge()` documented an `InvalidArgumentException`
  for a non-positive amount while nothing validated it — so the caller's own bug travelled to
  the gateway, came back a 4xx, and arrived as a *billing* failure the app is invited to
  swallow. That inverts the boundary exactly.
- `tests/Feature/ExceptionBoundaryTest.php` enforces this: it globs the contracts
  (inclusion by default — an allowlist is how the next contract escapes the sweep) and
  RESOLVES every `@throws` tag, so a type that does not exist, or belongs to neither side,
  fails the build. Do not weaken it into a substring check.
