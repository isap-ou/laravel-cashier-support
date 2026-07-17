# Spec: Money formatting API and moneyphp/money as the currency type

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#32](https://github.com/isap-ou/laravel-cashier-support/issues/32)

## Context & Goal

There is no public way to render a money amount, and the currency set is a closed whitelist.
Verified against `src/`:

- No `formatAmount` / `NumberFormatter` / `Money` anywhere in `src/`. The only formatting code is
  hand-rolled in `resources/views/invoice.blade.php:5-14` (`number_format` + `intdiv`): no locale,
  no currency symbol — it prints `EUR 12.00`.
- `Enums/Currency.php:12-26` is a 15-value whitelist; KRW, BRL, INR, TRY are not expressible in any
  DTO. `Currency::minorUnits()` (`:33-39`) returns `2` for everything except JPY — **wrong** for
  3-decimal currencies (BHD, KWD → 3) and other 0-decimal ones (KRW, VND).
- The enum is threaded through `DTO/{Payment,Invoice,Refund,CheckoutRequest}.php`,
  `Models/Invoice.php` (Eloquent cast `'currency' => Currency::class`) and
  `Invoice/InvoiceBuilder.php`.

**Goal:** a money amount renders correctly for a human — locale-aware, with the currency symbol and
the right number of decimals per ISO-4217 — through a public `Cashier::formatAmount()`; any
ISO-4217 currency is expressible in every DTO; and minor-unit data is correct by construction.

### The references, read from disk (not remembered)

Both Stripe and Paddle ship the same three things and use them everywhere a number is shown:

- `Cashier::formatAmount(int $amount, ?string $currency, ?string $locale, array $options)` —
  `vendor/laravel/cashier/src/Cashier.php:151-170`, via `moneyphp/money` + `IntlMoneyFormatter`.
  The **raw integer minor units go straight into `Money`** (`new Money($amount, new Currency(...))`),
  so no float arithmetic; `ISOCurrencies` supplies the per-currency decimals.
- `Cashier::formatCurrencyUsing(callable)` — `Cashier.php:137`.
- `currency_locale` config key — `config/cashier.php`, `'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en')`.

### Design decisions (settled with the maintainer)

1. **Adopt `moneyphp/money` (v4.9) as a real dependency** — data *and* formatting. It is already
   present transitively via the references and ships 179 ISO-4217 currencies with correct
   `minorUnit`. This resolves the issue's option 1 (issue comment 2). The earlier CLAUDE.md note
   "no money library" was never a ban (comment 1/2); the rule is updated to match.
2. **Remove `Enums\Currency` entirely.** Currency is represented everywhere as the moneyphp value
   object `Money\Currency`, so the market-restricting whitelist is gone and minor units come from
   `ISOCurrencies` — the current hand-rolled `minorUnits()` bug cannot recur. Chosen over "widen the
   typed enum" because a closed enum is exactly the restriction the issue objects to.
3. **Integrate the value object through custom casts**, not by leaking it raw: a spatie/laravel-data
   cast for DTOs and an Eloquent cast for the model. `Money\Currency` round-trips to its ISO code via
   `getCode()` / `__toString()` / `jsonSerialize()`.
4. **Formatting takes raw minor units** (mirrors the references) — no cents→float division.

## Functional requirements

**FR-1** — `composer.json` `require` gains `"moneyphp/money": "^4.9"`. Mandatory: any `use` of an
undeclared package is a fatal at class load, caught only by `tests/Feature/DeclaredDependenciesTest.php`
(#43).

**FR-2** — `config/cashier-support.php` gains `'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en')`
(with a comment block mirroring Stripe's). The existing `'currency'` default changes from
`Currency::EUR->value` to a plain `env('CASHIER_CURRENCY', 'EUR')` string; the `Enums\Currency` import
is dropped.

**FR-3** — `src/Casts/CurrencyCast.php` implements both `Spatie\LaravelData\Casts\Cast` and
`Spatie\LaravelData\Transformers\Transformer`: `cast()` builds `new Money\Currency(strtoupper($value))`
and **rejects** a code not in `ISOCurrencies` (throws — AC-6); `transform()` returns the ISO code.
`src/Casts/CurrencyEloquentCast.php` implements `Illuminate\Contracts\Database\Eloquent\CastsAttributes`:
`get()` string→`Money\Currency`, `set()` `Money\Currency|string`→ISO code string. A `Money\Currency`
value already present passes through unchanged.

**FR-4** — `CashierManager` gains, as direct methods (the manager is a singleton, and these are called
directly — never through `__call` → driver — so formatting works with no driver configured):
`protected ?Closure $formatCurrencyUsing = null;`, `formatCurrencyUsing(callable $callback): void`, and
`formatAmount(int $amount, Money\Currency|string|null $currency = null, ?string $locale = null, array $options = []): string`.
It mirrors `Cashier.php:151-170`: honour the custom callback first; else resolve the currency (a
`Money\Currency` → `getCode()`; a string as-is; null → `config('cashier-support.currency')`),
`strtoupper`, build `Money`, format via `IntlMoneyFormatter(new NumberFormatter($locale ?? config('cashier-support.currency_locale'), NumberFormatter::CURRENCY), new ISOCurrencies())`,
honouring `$options['min_fraction_digits']`. `Facades/Cashier.php` gains the two `@method` tags.

**FR-5** — `DTO/{Payment,Invoice,Refund,CheckoutRequest}.php`: the `currency` param changes from
`Currency` to `Money\Currency` (keeping `?` where nullable), annotated
`#[WithCastAndTransformer(CurrencyCast::class)]`. The `Enums\Currency` import becomes `Money\Currency`.

**FR-6** — `Models/Invoice.php` casts `'currency' => CurrencyEloquentCast::class` and updates its
`@property` type to `Money\Currency`. `Invoice/InvoiceBuilder.php` types its `currency` field and
`currency()` setter to `Money\Currency`, defaulting from `config('cashier-support.currency')`.

**FR-7** — `src/Enums/Currency.php` is deleted. `deptrac.yaml` gains a `Casts` layer
(`src/Casts/.*`); `DTO`, `Models` and `Invoice` rulesets gain `Casts`; the `Casts` layer depends on
nothing internal (moneyphp/spatie are external, uncovered). The `Enums` layer stays (other enums
remain).

**FR-8** — `resources/views/invoice.blade.php` replaces the `$money` closure and `$currencyCode`
prefixing with `\Isapp\CashierSupport\Facades\Cashier::formatAmount($cents, $invoice->currency)`. The
`$rate` helper (tax basis points → percent) is unchanged — it is not money.

## Acceptance criteria

**AC-1** — `Cashier::formatAmount(1234, 'EUR', 'de_DE')` → `12,34 €`.
**AC-2** — `Cashier::formatAmount(1234, 'JPY', 'en')` → `¥1,234` (no decimals).
**AC-3** — a BHD amount formats with 3 fraction digits (minor units from `ISOCurrencies`, not a hand list).
**AC-4** — KRW, BRL, INR, TRY (and any ISO-4217 code) hydrate as `Money\Currency` in every DTO and
`->toArray()` round-trips them back to the ISO code string.
**AC-5** — `Cashier::formatCurrencyUsing($cb)` overrides output; the invoice view renders via
`formatAmount` (locale-aware, symbol present) rather than the old `EUR 12.00`.
**AC-6** — an unknown code (e.g. `'ZZZ'`) is rejected at the cast boundary, not silently accepted.

## Non-goals

- No `Money\Money` value object in DTOs — amounts stay `int` minor units (only the currency becomes a
  value object). Formatting is the only place amounts meet moneyphp.
- No gateway/driver changes; no `Contracts` signature change (#28 untouched).
- No new migration: the `cashier_invoices.currency` column stays a string; only its cast changes.

## Edge cases

- **A `Money\Currency` already in hand** (not a string) passed to a cast/`formatAmount` → used as-is.
- **`ext-intl` absent** → `NumberFormatter` is unavailable; documented as a requirement (as Stripe does).
  Not newly guarded here.
- **Case** — lower-case input codes are upper-cased before lookup/format.

## Open questions

None outstanding — the currency-representation decision (value object + custom cast vs typed enum vs
ISO string) was settled with the maintainer before approval.
