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
- Do NOT invent method names — reference `laravel/cashier-stripe` v16
- Do NOT use @author, @package in docblocks