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
- **Do NOT** generate invoices locally (dompdf, spatie-pdf) as a workaround
- **Do NOT** simulate grace periods, pause/resume, or swap with cancel+create hacks
- **Do NOT** implement fake customer CRUD that only writes to local DB

If the provider API doesn't do it, the method throws. The app decides how to handle it.