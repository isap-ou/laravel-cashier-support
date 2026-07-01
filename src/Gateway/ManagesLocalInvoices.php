<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Invoice\InvoiceRenderer;
use Isapp\CashierSupport\Models\Invoice as InvoiceRecord;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default InvoiceOperations implementation for drivers whose provider has no
 * invoice API: invoices are local records (written by the driver, typically
 * from payment webhooks) rendered to PDF by cashier-support.
 *
 * The composing gateway supplies its driver name and an InvoiceRenderer.
 */
trait ManagesLocalInvoices
{
    protected InvoiceRenderer $invoiceRenderer;

    /**
     * The driver name whose invoice records this gateway owns.
     */
    abstract protected function driverName(): string;

    /**
     * {@inheritDoc}
     */
    public function invoices(Model $billable, array $parameters = []): array
    {
        return $this->invoiceQuery($billable)
            ->latest('issued_at')
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
     * display lines and seller details for the rendered PDF.
     */
    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response
    {
        $record = $this->findInvoiceRecord($billable, $invoiceId);

        if ($record === null) {
            abort(404, "Invoice [{$invoiceId}] not found.");
        }

        return $this->invoiceRenderer
            ->render($this->toInvoiceDto($record, $this->linesFrom($data)), $data)
            ->download("invoice-{$invoiceId}.pdf")
            ->toResponse(request());
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
            ->where(fn ($query) => $query->whereKey($invoiceId)->orWhere('provider_id', $invoiceId))
            ->first();
    }

    /**
     * @param  array<int, InvoiceLine>  $lines
     */
    private function toInvoiceDto(InvoiceRecord $record, array $lines = []): Invoice
    {
        $providerId = $record->getAttribute('provider_id');
        $number = $record->getAttribute('number');

        return new Invoice(
            id: is_string($providerId) && $providerId !== '' ? $providerId : (string) $record->getKey(),
            amount: $record->amount,
            currency: $record->currency,
            status: $record->status,
            number: is_string($number) ? $number : null,
            lines: $lines,
            issuedAt: $record->issued_at,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, InvoiceLine>
     */
    private function linesFrom(array $data): array
    {
        $lines = [];

        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = new InvoiceLine(
                    is_string($line['description'] ?? null) ? $line['description'] : '',
                    (int) ($line['amount'] ?? 0),
                    (int) ($line['quantity'] ?? 1),
                );
            }
        }

        return $lines;
    }
}
