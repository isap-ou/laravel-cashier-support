# Spec: InvoiceRenderer as a contract, supplied by the gateway

Status: Implemented

Issue: [isap-ou/laravel-cashier-support#33](https://github.com/isap-ou/laravel-cashier-support/issues/33)

## Context & Goal

`downloadInvoice()` works, but the PDF path is neither swappable nor deployable:

- `src/Invoice/InvoiceRenderer.php:17` is a concrete class hard-bound to
  `Spatie\LaravelPdf\Facades\Pdf`, returning `Spatie\LaravelPdf\PdfBuilder` (`:8-9,:25,:36`).
  There is no `Contracts\InvoiceRenderer`, no config key and no binding — nothing to override.
- `spatie/laravel-pdf` is a hard `require` (`composer.json:34`), yet *its* engine — Browsershot,
  i.e. Node + headless Chrome — is only in Spatie's `suggest`. A host app that installs us gets a
  renderer that fails at runtime until it provisions Chrome in production.

Both facts contradict the package invariant — "only interfaces, DTOs, enums… zero business logic"
(`CLAUDE.md`, `README.md`, see #38). The reference (Stripe cashier) does the opposite:
`Contracts/InvoiceRenderer` with swappable implementations selected by config, PDF libs in
`suggest`. We are simultaneously heavier in dependencies and weaker in abstraction than the
package we abstract.

**Goal:** support ships **only the contract**. Each provider knows how it generates its own
invoice and supplies a renderer **through its gateway** — the same shape as the webhook seam,
where `Contracts\RegistersWebhooks` is a segregated, `instanceof`-gated, driver-supplied
interface that is deliberately neither part of `GatewayProvider` nor a `Capability`. The blade
view and `invoices.*` config leave support with the engine. Delivery (download / save-to-disk)
stays in support; generation (engine + view) is the driver's.

### Why the renderer is not folded into `GatewayProvider`

`GatewayProvider` bundles all operation interfaces, so any method added to it is an instant
BC-break for every driver (#28). The webhook pattern already solved this for an optional,
driver-supplied capability by segregating it (`RegistersWebhooks`) and gating with `instanceof`
at the one call site (`Console\WebhookCommand.php:66`). Invoice rendering is the same shape: an
optional sub-mechanism of `downloadInvoice`, not a core capability of its own. So it gets a
segregated `Contracts\RendersInvoices`, gated inside `Gateway\ManagesLocalInvoices`, and **no**
new `Capability` case.

### Why the contract returns bytes, not a PDF builder

`render()` returns a raw `string` (the rendered document bytes), matching the reference's
`Contracts\InvoiceRenderer::render(): string`. From bytes, support derives both required
outputs — a streamed download `Response` and a saved file path — so a driver implements
generation once rather than implementing download and save separately. No Spatie type
(`PdfBuilder`) leaks into the contract.

## Functional requirements

**FR-1** — `Contracts\InvoiceRenderer` exists: `render(Invoice $invoice, array $data = []): string`,
returning the rendered document bytes (typically a PDF).

**FR-2** — `Contracts\RendersInvoices` exists: `invoiceRenderer(): InvoiceRenderer`. A gateway
that renders invoices locally implements it and returns its own concrete renderer.

**FR-3** — `Gateway\ManagesLocalInvoices` obtains its renderer only through the gateway: when the
composing gateway is not `instanceof RendersInvoices`, `downloadInvoice`/`storeInvoice` throw
`UnsupportedOperationException::forCapability(Capability::Invoices)`. No container resolution, no
constructor-injected renderer property.

**FR-4** — `downloadInvoice()` returns a Symfony `Response` carrying the rendered bytes with
`Content-Type: application/pdf` and `Content-Disposition: attachment; filename="invoice-<n>.pdf"`.
The filename is derived from the record number (falling back to its key), as today.

**FR-5** — `storeInvoice(Model $billable, string $invoiceId, array $data = [], ?string $disk = null,
?string $path = null): string` renders the invoice, writes the bytes via
`Storage::disk($disk ?? default)->put($path ?? 'invoices/<filename>', $bytes)`, and returns the
stored path. Exposed publicly on `Concerns\ManagesInvoices` behind `ensureCashierSupports(Capability::Invoices)`;
declared on `Contracts\InvoiceOperations`; refused by `Gateway\Defaults\RefusesInvoices`.

**FR-6** — `Capability::Invoices` detection stays bound to the current three methods
(`invoices`, `findInvoice`, `downloadInvoice`, `src/Enums/Capability.php:112`). `storeInvoice` is
NOT added to the detection map, so a driver's `Invoices` support does not flip on its presence.

**FR-7** — Support pulls no PDF engine: `spatie/laravel-pdf` leaves `composer.json` `require`
(no `suggest` entry — the engine is a driver concern). The invoice blade
(`resources/views/invoice.blade.php`) and the `invoices.*` config block leave support; the
service provider no longer loads or publishes those views.

## Acceptance criteria

**AC-1** — A gateway using `ManagesLocalInvoices` + implementing `RendersInvoices` with a fake
renderer: `downloadInvoice()` returns a 200 `Response`, `application/pdf`, attachment disposition
with the expected filename, body == the renderer's bytes. (FR-1..4)

**AC-2** — Same gateway: `storeInvoice()` under `Storage::fake()` returns a path,
`Storage::assertExists()` passes, stored bytes == the renderer's bytes; a custom `$disk`/`$path`
is honoured. (FR-5)

**AC-3** — A gateway using `ManagesLocalInvoices` but NOT `RendersInvoices`: `downloadInvoice()`
and `storeInvoice()` throw `UnsupportedOperationException` for capability `invoices`. (FR-3)

**AC-4** — The existing record-not-found branch still throws `NotFoundHttpException` before the
renderer is touched. (regression)

**AC-5** — `grep -rn "spatie/laravel-pdf\|Spatie\\LaravelPdf\|PdfBuilder" src config tests`
returns nothing. `composer.json` `require` has no PDF library. (FR-7)

## Non-goals

- Shipping any concrete renderer, blade view, or PDF engine from support — that is now the
  driver's job (a later, separately-tracked driver pass; see #33).
- Restoring parity with the reference's config-selected renderer binding — the seam here is the
  gateway, not a config key.
- Adding a `Capability::InvoiceRendering`. Rendering is gated by `instanceof RendersInvoices`,
  mirroring `RegistersWebhooks`, not by the capability system.
