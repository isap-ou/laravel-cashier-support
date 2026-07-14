# Research First — Never Trust Training Data

When working with any external API or third-party package:

- **ALWAYS** fetch and verify against official documentation before writing code, docs, or making claims
- **NEVER** rely on model training data for API capabilities, endpoints, parameters, or behavior
- **Use the `api-researcher` agent** (`.claude/agents/api-researcher/`) to look up official sources
- If official docs are unavailable, explicitly state that the information is unverified
- **Reference packages are read from disk, not remembered**: `vendor/laravel/cashier` (Stripe,
  primary) and `vendor/laravel/cashier-paddle` (second opinion) at the monorepo root.
  `vendor/mollie/laravel-cashier-mollie` is a last resort and not a design authority.

This applies to:
- Laravel Cashier Stripe method signatures and behavior
- Any third-party package APIs (spatie/laravel-data, moneyphp/money, etc.)
- Webhook events, statuses, enums from external services