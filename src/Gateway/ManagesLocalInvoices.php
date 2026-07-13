<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Invoice\InvoiceRenderer;
use Isapp\CashierSupport\Models\Invoice as InvoiceRecord;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Default InvoiceOperations implementation for drivers whose provider has no
 * invoice API: invoices are local records (written by the driver, typically
 * from payment webhooks) rendered to PDF by cashier-support.
 *
 * The composing gateway supplies its driver name; the renderer may be
 * constructor-injected or is lazily resolved from the container.
 */
trait ManagesLocalInvoices
{
    protected ?InvoiceRenderer $invoiceRenderer = null;

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
     * display lines and seller details for the rendered PDF.
     */
    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response
    {
        $record = $this->findInvoiceRecord($billable, $invoiceId);

        if ($record === null) {
            throw new NotFoundHttpException('Invoice not found.');
        }

        $number = $record->getAttribute('number');
        $filename = 'invoice-'.(is_string($number) && $number !== '' ? $number : (string) $record->getKey()).'.pdf';

        return $this->renderer()
            ->render($this->toInvoiceDto($record, $this->linesFrom($data)), $data)
            ->download($filename)
            ->toResponse(request());
    }

    /**
     * The renderer: constructor-injected by the gateway, or lazily resolved.
     */
    protected function renderer(): InvoiceRenderer
    {
        return $this->invoiceRenderer ??= app(InvoiceRenderer::class);
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
     * @param  array<int, InvoiceLine>  $lines
     */
    private function toInvoiceDto(InvoiceRecord $record, array $lines = []): Invoice
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
            lines: $lines,
            issuedAt: $record->issued_at,
            subscriptionId: is_string($subscriptionId) ? $subscriptionId : null,
            periodStart: $record->period_start,
            periodEnd: $record->period_end,
            billingReason: $record->billing_reason,
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
