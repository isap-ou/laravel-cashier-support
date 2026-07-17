---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Smart Stubs — No Custom Workarounds

When a provider does not natively support a feature:

- **DO** throw `UnsupportedOperationException` with a clear message
- **DO** check `Cashier::ensureSupports(Capability)` in Concerns before delegating
- **DO** let the app check `Cashier::supports(Capability)` before calling

- **Do NOT** build local subscription engines, cron-based billing, manual proration
- **Do NOT** simulate grace periods, pause/resume, or swap with cancel+create hacks
- **Do NOT** implement fake customer CRUD that only writes to local DB

If the provider API doesn't do it, the method throws. The app decides how to handle it.

**"Throws" beats "quietly does nothing", every time.** An ungated setter — one that accepts
a value the gateway has nowhere to put — is the same defect wearing a friendlier face: the
call succeeds, the data is dropped, and the app never learns. See `capabilities.md`.

**And do not half-support it.** Mapping a one-entry metadata array onto a gateway's single
reference field would make the same call work or fail depending on how much the caller
happened to put in the array. If the gateway has no such concept, say so and expose the
concept it *does* have under its own name.

## Provider-independent features (NOT workarounds)

Invoice DATA is assembled locally by cashier-support from payment/subscription data stored in the DB.
This is a shared feature for all providers, not a workaround for a missing API.
Lives in `src/Invoice/InvoiceBuilder.php` and `src/Models/Invoice.php`. RENDERING that data to a
document is the driver's, not this package's: support ships only `Contracts\InvoiceRenderer`,
supplied through the gateway via `Contracts\RendersInvoices` — no PDF engine is pinned here (#33).