---
description: Track upstream laravel/cashier-stripe for API changes since our pinned baseline and map each change onto this package's Contracts/Concerns/DTOs/Enums/Events so we can adapt. Run on-demand or on a schedule.
argument-hint: "[--since <version>] [--update-baseline]"
---

# Track Cashier — Upstream Drift Detector

Detect what changed in **`laravel/cashier-stripe`** since the version this package
mirrors, and turn that into a concrete, file-by-file adaptation list for
`isapp/laravel-cashier-support`.

This skill only **reads** upstream and **reports**. It never edits `src/` on its own.
It updates `baseline.md` only when invoked with `--update-baseline` (or after you
explicitly approve), because the baseline is the contract for "what we currently target".

## Why this exists

`cashier-support` mirrors the Stripe Cashier public API 1:1 (see `CLAUDE.md`).
When upstream adds a method, renames one, changes a signature, adds a webhook event,
or changes a subscription status, our contracts/DTOs/enums silently fall out of parity.
This skill closes that gap on a cadence instead of by accident.

## Sources of truth (in priority order — never trust training data)

Follow `.claude/rules/sources-of-truth.md` and `.claude/rules/research-first.md`.
Fetch live; cite every URL in the report.

1. Version + requirements — `https://packagist.org/packages/laravel/cashier.json`
2. Changelog — `https://raw.githubusercontent.com/laravel/cashier-stripe/master/CHANGELOG.md`
3. Release notes — `https://github.com/laravel/cashier-stripe/releases`
4. Source of the mirrored surface (raw files, `<TAG>` = the version being inspected):
   - `https://raw.githubusercontent.com/laravel/cashier-stripe/<TAG>/src/Concerns/ManagesCustomer.php`
   - `.../src/Concerns/ManagesSubscriptions.php`
   - `.../src/Concerns/ManagesPaymentMethods.php`
   - `.../src/Concerns/ManagesInvoices.php`
   - `.../src/Concerns/PerformsCharges.php`
   - `.../src/Concerns/HandlesPaymentFailures.php`
   - `.../src/Concerns/ManagesTax.php`
   - `.../src/Concerns/AllowsCoupons.php`
   - `.../src/Subscription.php`
   - `.../src/SubscriptionBuilder.php`
   - `.../src/Checkout.php`
   - `.../src/Events/` (directory listing via the GitHub API — see below)
   - `.../src/Http/Controllers/WebhookController.php` (handled webhook event set)
5. Docs — `https://laravel.com/docs/12.x/billing`

GitHub directory listings (to catch **new** files, not just changed ones):
`https://api.github.com/repos/laravel/cashier-stripe/contents/src/Events?ref=<TAG>`
(use `gh api repos/laravel/cashier-stripe/contents/src/Events?ref=<TAG>` if `gh` is available —
it avoids rate limits; otherwise `WebFetch` the same URL).

Prefer dispatching the **`api-researcher`** agent (`.claude/agents/api-researcher/`) for the
fetch-and-extract work when it exists; fall back to `WebFetch` / `gh`.

## Procedure

1. **Read the baseline.** Open `baseline.md` (same directory). Record
   `baseline.version` and the mirrored-surface manifest. If `--since <version>` is
   passed, use that instead of `baseline.version` as the comparison floor.

2. **Get the latest upstream version.** Fetch the packagist JSON. Extract the highest
   stable tag (ignore `-dev`, `-beta`, `-RC` unless `--since` explicitly targets one).

3. **Short-circuit if unchanged.** If `latest == baseline.version`, emit
   `✅ In parity with laravel/cashier-stripe <version> (checked <date>)` and stop.
   (Get the date from the environment/user — do not fabricate one.)

4. **Read the changelog delta.** Fetch `CHANGELOG.md` and extract every entry with a
   version `> baseline.version` and `<= latest`. Keep the PR/commit links.

5. **Diff the mirrored surface.** For each source file in "Sources" §4, fetch it at
   `<TAG>=latest` and compare its **public** method signatures against our manifest in
   `baseline.md`. Also list `src/Events/*` and the webhook event set at `latest` and
   diff the *names* against the manifest. You are looking for:
   - **Added** — a public method / event / status / webhook that we do not mirror.
   - **Changed** — same name, different signature (params, types, return).
   - **Removed** — something in our manifest that upstream deleted.
   - **Renamed** — a removal + addition that are obviously the same concept.
   - **Deprecated** — `@deprecated` docblocks or changelog "deprecate" notes.

6. **Map to our package.** For every change, resolve the touch-point in *our* tree using
   the mapping table below. A change with no mapping row is itself a finding
   ("no mirror exists yet").

7. **Emit the report** in the format below. Rank by impact: breaking (Changed/Removed) first,
   then Added, then Deprecated, then informational.

8. **Baseline update (gated).** Only if invoked with `--update-baseline` *or* after the
   user explicitly approves the report: rewrite `baseline.md` with the new
   `version`, refreshed manifest, and the check date. Never bump the baseline silently —
   an un-bumped baseline is what makes the next run detect the same drift again.

## Mapping table — upstream symbol → our file

| Upstream (cashier-stripe) | Our mirror in `src/` |
|---|---|
| `Billable::charge/refund` (`PerformsCharges`) | `Contracts/ChargeOperations.php`, `Concerns/PerformsCharges.php` |
| `Billable::createAsCustomer/asCustomer/...` (`ManagesCustomer`) | `Contracts/CustomerOperations.php`, `Concerns/ManagesCustomer.php`, `DTO/Customer.php` |
| `Billable::newSubscription/subscription/subscribed/...` (`ManagesSubscriptions`) | `Contracts/SubscriptionOperations.php`, `Concerns/ManagesSubscriptions.php`, `DTO/Subscription.php` |
| `Billable::addPaymentMethod/defaultPaymentMethod/...` (`ManagesPaymentMethods`) | `Contracts/PaymentMethodOperations.php`, `Concerns/ManagesPaymentMethods.php`, `DTO/PaymentMethod.php` |
| `Billable::invoices/downloadInvoice/...` (`ManagesInvoices`) | `Contracts/InvoiceOperations.php`, `Concerns/ManagesInvoices.php`, `DTO/Invoice.php`, `DTO/InvoiceLine.php` |
| `Billable::checkout/...` (`Checkout`) | `Contracts/CheckoutOperations.php`, `Concerns/HandlesCheckout.php`, `DTO/CheckoutSession.php`, `Enums/CheckoutMode.php` |
| `Billable::taxRates/...` (`ManagesTax`) | `Concerns/HandlesTaxes.php` |
| `SubscriptionBuilder` methods (`trialDays`, `create`, ...) | `Contracts/SubscriptionBuilder.php` |
| `Subscription` model methods (`cancel/resume/swap/...`) | `Contracts/SubscriptionOperations.php`, `Models/Subscription.php`, `Models/SubscriptionItem.php` |
| Subscription statuses (`active/past_due/trialing/...`) | `Enums/SubscriptionStatus.php` |
| Charge/payment statuses | `Enums/PaymentStatus.php` |
| `src/Events/*` | `Events/*` |
| Webhook events handled in `WebhookController` | `Enums/WebhookEvent.php`, `Contracts/WebhookHandler.php`, `DTO/WebhookPayload.php` |
| Refund reasons / behaviour | `Enums/RefundReason.php`, `DTO/Refund.php` |

Note our **Capability** layer: some upstream features may map to a capability gate
(`Enums/Capability.php`) rather than a new method — a provider that lacks the feature
throws `UnsupportedOperationException`. Flag when a new upstream method deserves a new
`Capability` case.

## Report format

```
# Cashier drift report — <baseline.version> → <latest> (checked <date>)

## ⚠️ Breaking (Changed / Removed) — N
- [Changed] Subscription::swap($prices, $options) — added $options param
  upstream: <url>  | our file: src/Contracts/SubscriptionOperations.php
  adaptation: add `array $options = []` to swap() signature + PHPDoc

## ➕ Added — N
- [Added] Billable::previewInvoice()
  upstream: <url>  | our mirror: none yet
  adaptation: add InvoiceOperations::previewInvoice(): Invoice; consider Capability::InvoicePreview

## 🗑 Deprecated — N
- ...

## ℹ️ Informational (docs / internal) — N
- ...

## Suggested baseline bump
version: <latest>   (run again with --update-baseline to persist)
```

## Automation — running this "constantly"

The skill is the *check*; schedule it so drift is caught on a cadence:

- **Cloud routine** — `/schedule` a weekly run: "Invoke the `track-cashier` skill; if the
  report has any Breaking or Added items, summarise them." (See the harness `schedule` skill.)
- **CI cron** — a weekly GitHub Actions job that runs this procedure headlessly and opens an
  issue when the report is non-empty.

Either way, keep `baseline.md` under version control so the diff floor is shared by the team.

## Rules

- Research-first: fetch live sources, never answer from training data; cite every URL.
- Never invent upstream method names, params, or event names — quote the fetched source.
- Do not edit `src/` from this skill. It reports; adaptation is a normal dev-flow task.
- Update `baseline.md` only when explicitly told to (`--update-baseline` or approval).
