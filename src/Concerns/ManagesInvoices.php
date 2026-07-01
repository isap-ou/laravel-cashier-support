<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\Enums\Capability;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoice access for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait ManagesInvoices
{
    use InteractsWithProvider;

    /**
     * List invoices for the entity.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<int, Invoice>
     */
    public function invoices(array $parameters = []): array
    {
        $this->ensureCashierSupports(Capability::Invoices);

        return $this->cashierProvider()->invoices($this, $parameters);
    }

    /**
     * Find a single invoice by identifier.
     */
    public function findInvoice(string $invoiceId): ?Invoice
    {
        $this->ensureCashierSupports(Capability::Invoices);

        return $this->cashierProvider()->findInvoice($this, $invoiceId);
    }

    /**
     * Download an invoice as a response (typically a PDF).
     *
     * @param  array<string, mixed>  $data
     */
    public function downloadInvoice(string $invoiceId, array $data = []): Response
    {
        $this->ensureCashierSupports(Capability::Invoices);

        return $this->cashierProvider()->downloadInvoice($this, $invoiceId, $data);
    }
}
