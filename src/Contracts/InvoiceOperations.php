<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoice retrieval operations at the gateway provider.
 */
interface InvoiceOperations
{
    /**
     * List invoices for the billable entity.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<int, Invoice>
     *
     * @throws UnsupportedOperationException When the provider does not support invoices.
     * @throws CashierException When the gateway call fails.
     */
    public function invoices(Model $billable, array $parameters = []): array;

    /**
     * Find a single invoice by identifier.
     *
     * @throws UnsupportedOperationException When the provider does not support invoices.
     * @throws CashierException When the gateway call fails.
     */
    public function findInvoice(Model $billable, string $invoiceId): ?Invoice;

    /**
     * Build a downloadable response (typically a PDF) for an invoice.
     *
     * @param  array<string, mixed>  $data  Additional data for rendering (seller, notes, ...).
     *
     * @throws UnsupportedOperationException When the provider does not support invoices.
     * @throws CashierException When the invoice does not exist or cannot be rendered.
     */
    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response;

    /**
     * Render an invoice, write it to a disk, and return the stored path.
     *
     * @param  array<string, mixed>  $data  Additional data for rendering (seller, notes, ...).
     * @param  string|null  $disk  Filesystem disk; null uses the application default.
     * @param  string|null  $path  Target path; null uses `invoices/<filename>`.
     *
     * @throws UnsupportedOperationException When the provider does not support invoices.
     * @throws CashierException When the invoice does not exist or cannot be rendered.
     */
    public function storeInvoice(Model $billable, string $invoiceId, array $data = [], ?string $disk = null, ?string $path = null): string;
}
