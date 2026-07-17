# Spec: a missing invoice is a catchable `InvoiceNotFoundException`

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#68](https://github.com/isap-ou/laravel-cashier-support/issues/68)

## Context & Goal

`Gateway\ManagesLocalInvoices::renderInvoiceRecord()` throws Symfony `NotFoundHttpException` for a
missing or non-owned invoice, but `Contracts\InvoiceOperations::downloadInvoice`/`storeInvoice` declare
`@throws CashierException When the invoice does not exist or cannot be rendered`. `NotFoundHttpException`
extends `RuntimeException`, not `CashierException` — so an app's `catch (CashierException)` misses the
not-found case and the contract's `@throws` is a lie. `.claude/rules/exceptions.md` makes an
entity-that-does-not-exist catchable as `CashierException`; `Exceptions\CustomerNotFoundException`
(thrown from `Gateway\ManagesCustomerRecords`) is the live precedent. `ExceptionBoundaryTest` does not
catch the mismatch — it resolves only declared `@throws` tags on contracts, never method bodies.

**Goal:** a missing/foreign invoice throws a domain `Exceptions\InvoiceNotFoundException extends
CashierException`, so `catch (CashierException)` works and the contract's `@throws` is honest.

### Deliberate divergence from the reference

Stripe Cashier's `findInvoiceOrFail` (`vendor/laravel/cashier/src/Concerns/ManagesInvoices.php:287-300`)
throws Symfony `NotFoundHttpException` (404) / `AccessDeniedHttpException` (403) for missing / non-owned;
Paddle relies on relation-scoping (→ 404). Both treat invoice-not-found as an HTTP-layer concern. We
knowingly diverge: this package's own exception boundary (`.claude/rules/exceptions.md`) says a
not-found entity is a catchable billing fact, and `CustomerNotFoundException` already encodes exactly
that. Internal consistency wins here over reference-parity, and the divergence is recorded in the
CHANGELOG.

## Functional requirements

**FR-1** — `Exceptions\InvoiceNotFoundException extends CashierException`, with a static factory
`::withId(string $invoiceId): self` → `"No invoice found for identifier [{$invoiceId}]."` (mirrors
`CustomerNotFoundException`).

**FR-2** — `Gateway\ManagesLocalInvoices::renderInvoiceRecord()` throws `InvoiceNotFoundException::withId($invoiceId)`
(not `NotFoundHttpException`) when the record is absent or not the billable's. No Symfony HTTP-exception
import remains in the trait.

**FR-3** — `Contracts\InvoiceOperations::downloadInvoice`/`storeInvoice` declare accurate throws:
`UnsupportedOperationException` (unsupported), `InvoiceNotFoundException` (does not exist / not the
billable's), `CashierException` (cannot be rendered). All three are CashierException-side, so the
boundary sweep stays green.

**FR-4** — `deptrac.yaml` gains an unreachable `SymfonyHttpException` layer
(`Symfony\Component\HttpKernel\Exception\.*`), so no domain layer may throw a Symfony HTTP exception
again (mirrors the `HttpClient: ~` guardrail).

## Acceptance criteria

**AC-1** — On a rendering gateway, `downloadInvoice`/`storeInvoice` for a missing or foreign invoice id
throw `InvoiceNotFoundException`, and the thrown value is `instanceof CashierException`. (FR-1..3)

**AC-2** — The render gate still wins: a gateway using `ManagesLocalInvoices` without `RendersInvoices`
throws `UnsupportedOperationException`, even for a missing id (unchanged). (regression)

**AC-3** — `grep -rn "NotFoundHttpException" src tests` returns nothing; `vendor/bin/deptrac` passes and
would fail if a Symfony HTTP exception were re-added to `src/Gateway`. (FR-2, FR-4)

**AC-4** — `ExceptionBoundaryTest` (hierarchy + contract sweep) stays green — the new exception extends
`CashierException` and the new `@throws` tags resolve. (FR-1, FR-3)

## Non-goals

- Matching the reference's separate 403 (`AccessDeniedHttpException`) for a non-owned invoice — the local
  query scopes by owner, so a foreign invoice reads as not-found; distinguishing them is out of scope.
- Changing `findInvoice()` (returns `?Invoice`, unchanged).
