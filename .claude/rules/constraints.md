---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Constraints

- Do NOT add HTTP calls, cURL, Guzzle, Http::client — no network code
- Do NOT add business logic — only abstractions
- Do NOT use float for money — only int (minor units / cents)
- Do NOT create provider implementations (Stripe, Revolut, Adyen) in this package
- Do NOT invent method names — reference `laravel/cashier-stripe` v16. This governs the
  surface an app calls: `Billable` concerns, contracts, models. Where Cashier has a name,
  that name wins even if ours would read better. Where Cashier expresses the concept
  *without* a name — inline comparisons, a static flag, no equivalent type at all — there
  is nothing to borrow, and an internal predicate may be coined: say in its docblock which
  reference lines it encodes, so the next reader can check the semantics against the source
  rather than the name (`SubscriptionStatus::isActive()`, `::deniesAccess()`)
- Do NOT use @author, @package in docblocks