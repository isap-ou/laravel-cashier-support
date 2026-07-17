# Spec: Invoice DTOs express tax, discount and subtotal

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#31](https://github.com/isap-ou/laravel-cashier-support/issues/31)

## Context & Goal

A VAT invoice is not *expressible* today — not "the driver can't do tax" but "the contract has
nowhere to put it". Verified against `src/`:

- `DTO/Invoice.php:23-36` — `amount` is the only money field; no `subtotal` / `tax` / `discount`.
- `DTO/InvoiceLine.php:17-21` — three fields: `description, amount, quantity`. No unit amount, no tax.
- `resources/views/invoice.blade.php:55-78` — renders Description / Qty / Amount / Total only.
- `Gateway/ManagesLocalInvoices.php:127-147` — `toInvoiceDto()` always sets `lines: []`; lines
  only ever come from caller-supplied `$data['lines']` at download time (`:153-168`). Nothing
  persists lines or tax.
- `database/migrations/create_cashier_invoices_table.php` — one `bigInteger('amount')`, no tax
  or line storage.
- `Enums/Capability.php` — no `Discounts` case; an app cannot even ask whether a driver supports them.

**Goal:** a VAT-bearing invoice round-trips gateway → DTO → DB → PDF, with the tax line visible;
and an app can ask whether a driver supports discounts.

### The references, read from disk (not remembered)

Both agree on an **aggregate** tax amount; Stripe additionally exposes a per-rate breakdown.

- **Paddle** (`vendor/laravel/cashier-paddle`) persists a single `tax` column on its transactions
  table (`database/migrations/2019_05_03_000004_create_transactions_table.php:22`) and reads it
  back as one aggregate (`Transaction::tax()`). No per-rate table.
- **Stripe** (`vendor/laravel/cashier`) exposes both: an aggregate `Invoice::tax(): string`
  (`Invoice.php:379`) and `InvoiceLineItem::totalTaxAmount(): int` (`:266`), *and* an optional
  per-rate breakdown `Invoice::taxes(): array` of `Tax` (`:403`). Discounts likewise: aggregate
  `rawDiscount(): int` (`:363`) plus `discounts(): array`.

Per the package rule (Stripe/Paddle agreement is the shape; the difference is what a `Capability`
is for), the aggregate is the shape this ticket ships; the per-rate breakdown is a future
capability, not this one. Money stays `int` minor units across both references (raw methods);
`moneyphp/money` is used only at the formatting edge — out of scope here (#32).

### Why this does not touch #28

`DTO\Invoice` / `DTO\InvoiceLine` are `Spatie\LaravelData\Data` classes, not interfaces. Adding
**new optional constructor params** is backward-compatible and does not alter
`Contracts\InvoiceOperations`, so the interface-segregation blocker (#28) — which governs contract
*methods* — is untouched. CLAUDE.md names optional-param addition the low-friction path.

### Design decisions

1. **Tax = scalar aggregate**, not a `taxes[]` breakdown array (references agree on the aggregate).
2. **Lines persisted as a JSON column** `lines` on `cashier_invoices` — **not** a new
   `cashier_invoice_lines` table. This deliberately diverges from the issue's literal Fix text;
   it is the maintainer's decision and avoids a new model/relation.
3. **Discounts = flag + amount only**: `Capability::Discounts` + `int discount` on `Invoice`.
   A full coupon/promotion model stays deferred (CLAUDE.md "Not implemented").
4. **`taxRate` is `int` basis points** (e.g. `2000` = 20.00%) — float-free; documented on the field.
5. **`amount` stays the canonical total.** Renaming `amount` → `total` is a fatal BC break, so the
   new fields are `subtotal` / `tax` / `discount`; `amount` *is* the total.

## Functional requirements

**FR-1** — `DTO\InvoiceLine` gains three optional params, appended after `quantity`, all nullable
ints (minor units, except the rate): `?int $unitAmount = null`, `?int $taxAmount = null`,
`?int $taxRate = null` (basis points). Existing `description, amount, quantity` order is untouched.

**FR-2** — `DTO\Invoice` gains three optional params, appended after `billingReason`, nullable int
minor units: `?int $subtotal = null`, `?int $tax = null`, `?int $discount = null`. The docblock
records that `amount` remains the total and that `subtotal + tax − discount` reconciles to it when
all three are present. New params go last so positional callers keep working.

**FR-3** — `Enums\Capability::Discounts = 'discounts'` is added. It is a data-shape flag, not a
gateway method, so it joins the `=> []` arm of `Capability::methods()` and is declared via
`Gateway\BaseGateway::declaredCapabilities()` (like `Taxes`). The three count statements that
track the method-backed / declared split (`Capability::methods()` docblock, `Gateway\BaseGateway`,
`CLAUDE.md`) are updated — the current numbers are verified against the code before editing (#38).

**FR-4** — `cashier_invoices` gains, in the existing create migration (no installations to migrate
forward, per the pause-timing precedent): `bigInteger('subtotal')->nullable()`,
`bigInteger('tax')->nullable()`, `bigInteger('discount')->nullable()`, `json('lines')->nullable()`.
`Models\Invoice` casts `subtotal/tax/discount => integer`, `lines => array`, and lists the three
new money fields in its `@property` block.

**FR-5** — `Gateway\ManagesLocalInvoices::toInvoiceDto()` reads the new shape from the record:
lines are rebuilt from the persisted `lines` JSON (into `InvoiceLine` DTOs) instead of the `[]`
default, and `subtotal/tax/discount` are read from the record. `invoices()` and `findInvoice()`
therefore return populated lines and tax. `downloadInvoice()` keeps caller-supplied `$data['lines']`
as an **override** (display lines win when provided; otherwise persisted lines are used).
`linesFrom()` is extended to read the new per-line keys.

**FR-6** — `Invoice\InvoiceBuilder` gains a tax/discount surface: `addLine()` takes
`?int $unitAmount = null, ?int $taxAmount = null, ?int $taxRate = null`; new `tax(int)` and
`discount(int)` setters. `build()` sets `amount` = Σ line `amount` + (tax ?? 0) − (discount ?? 0).
The breakdown fields stay **null** unless `tax()` / `discount()` were called — so an invoice with
no VAT reports no breakdown (and the view shows only the Total) rather than a row of zeros; when a
breakdown is present, `subtotal` carries Σ line `amount`. With no tax/discount, `amount` equals the
line sum and the existing builder test still passes.

**FR-7** — `resources/views/invoice.blade.php` renders the VAT shape. Per line: a Unit column and a
Tax column, each shown only when the line carries the value. Footer: Subtotal, Tax and Discount
rows (each shown only when the invoice carries a non-null value) above the existing Total. The
existing integer `$money()` formatter is reused; `taxRate` renders as a percentage from basis
points with no float touching a money amount.

## Acceptance criteria

**AC-1** (the issue's criterion) — A VAT-bearing invoice round-trips gateway → DTO → DB → PDF with
the tax line visible: a persisted `cashier_invoices` record carrying `lines` JSON with
`unitAmount/taxAmount/taxRate` plus `subtotal/tax/discount`, read back through
`ManagesLocalInvoices::findInvoice()`, yields a DTO with lines + tax + subtotal + discount; rendering
it produces HTML containing the subtotal and tax rows and the per-line tax amount.

**AC-2** — `DTO\Invoice` / `DTO\InvoiceLine` construct with and without the new fields; a positional
call using only the old params still works (BC), and `->toArray()` includes the new keys.

**AC-3** — `InvoiceBuilder` with two lines + a tax + a discount yields `subtotal` = Σ line amounts,
`amount` = `subtotal + tax − discount`, and the lines carry their per-line fields.

**AC-4** — `Capability::Discounts` exists, `methods()` returns `[]` for it, and the split counts in
the three docs match the code.

## Non-goals

- No `taxes[]` per-rate breakdown DTO; no `Tax` / `Discount` value objects (decision 1).
- No `cashier_invoice_lines` table, no line model/relation (decision 2).
- No coupon / promotion-code model, no `Coupon` / `PromotionCode` DTOs (decision 3, deferred).
- No `moneyphp/money`, no `Currency` whitelist change (#32, separate).
- No driver changes. Revolut has no tax/line data to supply (its order resource is one gross
  `amount`); the new fields stay null there. A driver write-side (persisting `lines`/`tax` from
  webhooks) is a follow-up, out of scope.
- No `Contracts\InvoiceOperations` signature change (#28 untouched).

## Edge cases

- **Old rows with `lines` NULL** → `toInvoiceDto()` yields `lines: []` (unchanged behaviour); the
  `$data['lines']` download override still applies. No data migration needed.
- **`amount` vs `subtotal + tax − discount`** — a driver may set an authoritative `amount` from the
  gateway and leave the breakdown null; the invoice is still valid (the breakdown is optional). The
  builder is the only place that *derives* `amount`.
- **`taxRate` basis points** — display divides by 100 for a percentage string; no float touches a
  money amount.

## Open questions

None outstanding — the five design decisions were settled with the maintainer before approval.
