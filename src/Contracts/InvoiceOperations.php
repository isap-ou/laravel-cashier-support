<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Invoice;
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
     */
    public function invoices(Model $billable, array $parameters = []): array;

    /**
     * Find a single invoice by identifier.
     */
    public function findInvoice(Model $billable, string $invoiceId): ?Invoice;

    /**
     * Build a downloadable response (typically a PDF) for an invoice.
     *
     * @param  array<string, mixed>  $data  Additional data for rendering (seller, notes, ...).
     */
    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response;
}
