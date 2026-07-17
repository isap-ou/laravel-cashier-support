<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Isapp\CashierSupport\Contracts\InvoiceRenderer;
use Isapp\CashierSupport\Contracts\RendersInvoices;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\InvoiceNotFoundException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Invoice as InvoiceRecord;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default InvoiceOperations implementation for drivers whose provider has no
 * invoice API: invoices are local records (written by the driver, typically
 * from payment webhooks) rendered by the driver's own InvoiceRenderer.
 *
 * The composing gateway supplies its driver name and, because it must also render,
 * implements Contracts\RendersInvoices — this package ships no renderer and pins no
 * PDF engine. A gateway that mixes this trait in without implementing RendersInvoices
 * refuses download/store: rendering is the driver's, delivery is ours.
 */
trait ManagesLocalInvoices
{
    /**
     * The driver name whose invoice records this gateway owns.
     */
    abstract protected function driverName(): string;

    /**
     * {@inheritDoc}
     *
     * Supported parameters: 'limit' (int) — cap the number of records.
     */
    public function invoices(Model $billable, array $parameters = []): array
    {
        $query = $this->invoiceQuery($billable)->latest('issued_at');

        if (is_int($parameters['limit'] ?? null)) {
            $query->limit($parameters['limit']);
        }

        return $query
            ->get()
            ->map(fn (InvoiceRecord $record): Invoice => $this->toInvoiceDto($record))
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function findInvoice(Model $billable, string $invoiceId): ?Invoice
    {
        $record = $this->findInvoiceRecord($billable, $invoiceId);

        return $record !== null ? $this->toInvoiceDto($record) : null;
    }

    /**
     * {@inheritDoc}
     *
     * The invoice must belong to the billable entity; optional $data may carry
     * display lines and seller details for the rendered document.
     */
    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response
    {
        [$bytes, $filename] = $this->renderInvoiceRecord($billable, $invoiceId, $data);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Renders the invoice and writes it to a disk, returning the stored path. Defaults to
     * the application's default disk and an `invoices/<filename>` path.
     */
    public function storeInvoice(Model $billable, string $invoiceId, array $data = [], ?string $disk = null, ?string $path = null): string
    {
        [$bytes, $filename] = $this->renderInvoiceRecord($billable, $invoiceId, $data);

        $target = $path ?? 'invoices/'.$filename;

        Storage::disk($disk)->put($target, $bytes);

        return $target;
    }

    /**
     * Look the record up (404 when it is not this billable's), then render it to bytes.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string} [rendered bytes, filename]
     */
    private function renderInvoiceRecord(Model $billable, string $invoiceId, array $data): array
    {
        // Resolve the renderer FIRST: a gateway that cannot render refuses unconditionally,
        // before — and regardless of — whether the requested record happens to exist. Looking
        // the record up first would mask the missing-renderer misconfiguration behind a 404.
        $renderer = $this->renderer();

        $record = $this->findInvoiceRecord($billable, $invoiceId);

        if ($record === null) {
            throw InvoiceNotFoundException::withId($invoiceId);
        }

        $number = $record->getAttribute('number');
        $filename = 'invoice-'.(is_string($number) && $number !== '' ? $number : (string) $record->getKey()).'.pdf';

        // Caller-supplied display lines override the persisted ones; absent them, the DTO uses
        // whatever the record stored (see toInvoiceDto).
        $override = isset($data['lines']) && is_array($data['lines']) ? $this->linesFrom($data) : null;

        $bytes = $renderer->render($this->toInvoiceDto($record, $override), $data);

        return [$bytes, $filename];
    }

    /**
     * The renderer is the gateway's own — this package ships none. A gateway that renders
     * local invoices implements Contracts\RendersInvoices; one that does not cannot download
     * or store, and says so rather than resolving a renderer that was never provided.
     */
    protected function renderer(): InvoiceRenderer
    {
        if (! $this instanceof RendersInvoices) {
            throw UnsupportedOperationException::forCapability(Capability::Invoices);
        }

        return $this->invoiceRenderer();
    }

    /**
     * @return Builder<InvoiceRecord>
     */
    private function invoiceQuery(Model $billable): Builder
    {
        $model = Cashier::invoiceModel($this->driverName());

        return $model::query()
            ->where('provider', $this->driverName())
            ->where('owner_type', $billable->getMorphClass())
            ->where('owner_id', $billable->getKey());
    }

    private function findInvoiceRecord(Model $billable, string $invoiceId): ?InvoiceRecord
    {
        /** @var InvoiceRecord|null */
        return $this->invoiceQuery($billable)
            ->where(function ($query) use ($invoiceId): void {
                // The primary key is a native uuid column on PostgreSQL —
                // comparing it against a non-uuid provider id would throw.
                if (Str::isUuid($invoiceId)) {
                    $query->whereKey($invoiceId);
                }

                $query->orWhere('provider_id', $invoiceId);
            })
            ->first();
    }

    /**
     * Hydrate the DTO from a record. When $lines is null the persisted line JSON is used;
     * pass an array (e.g. caller-supplied display lines) to override it.
     *
     * @param  array<int, InvoiceLine>|null  $lines
     */
    private function toInvoiceDto(InvoiceRecord $record, ?array $lines = null): Invoice
    {
        $providerId = $record->getAttribute('provider_id');
        $number = $record->getAttribute('number');

        $subscriptionId = $record->getAttribute('subscription_id');

        return new Invoice(
            id: is_string($providerId) && $providerId !== '' ? $providerId : (string) $record->getKey(),
            amount: $record->amount,
            currency: $record->currency,
            status: $record->status,
            number: is_string($number) ? $number : null,
            lines: $lines ?? $this->persistedLines($record),
            issuedAt: $record->issued_at,
            subscriptionId: is_string($subscriptionId) ? $subscriptionId : null,
            periodStart: $record->period_start,
            periodEnd: $record->period_end,
            billingReason: $record->billing_reason,
            subtotal: $record->subtotal,
            tax: $record->tax,
            discount: $record->discount,
        );
    }

    /**
     * Line items stored on the record's `lines` JSON column. Empty on legacy rows.
     *
     * @return array<int, InvoiceLine>
     */
    private function persistedLines(InvoiceRecord $record): array
    {
        $stored = $record->getAttribute('lines');

        return $this->linesFromArray(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, InvoiceLine>
     */
    private function linesFrom(array $data): array
    {
        return $this->linesFromArray(is_array($data['lines'] ?? null) ? $data['lines'] : []);
    }

    /**
     * Build InvoiceLine DTOs from a list of associative arrays (persisted JSON or caller data).
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int, InvoiceLine>
     */
    private function linesFromArray(array $rows): array
    {
        $lines = [];

        foreach ($rows as $line) {
            if (is_array($line)) {
                $lines[] = new InvoiceLine(
                    is_string($line['description'] ?? null) ? $line['description'] : '',
                    (int) ($line['amount'] ?? 0),
                    (int) ($line['quantity'] ?? 1),
                    isset($line['unitAmount']) ? (int) $line['unitAmount'] : null,
                    isset($line['taxAmount']) ? (int) $line['taxAmount'] : null,
                    isset($line['taxRate']) ? (int) $line['taxRate'] : null,
                );
            }
        }

        return $lines;
    }
}
