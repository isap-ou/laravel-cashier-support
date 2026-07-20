<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Guards;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\Enums\Capability;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capability gating for the InvoiceOperations surface, composed into GuardedProvider.
 *
 * @internal Composed into Gateway\GuardedProvider, which is what Cashier::provider() returns. An app reaches this through the facade, never by name. Not public surface: outside the backward-compatibility promise in README.
 */
trait GuardsInvoices
{
    /**
     * {@inheritDoc}
     */
    public function invoices(Model $billable, array $parameters = []): array
    {
        $this->ensure(Capability::Invoices);

        return $this->inner()->invoices($billable, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function findInvoice(Model $billable, string $invoiceId): ?Invoice
    {
        $this->ensure(Capability::Invoices);

        return $this->inner()->findInvoice($billable, $invoiceId);
    }

    /**
     * {@inheritDoc}
     */
    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response
    {
        $this->ensure(Capability::Invoices);

        return $this->inner()->downloadInvoice($billable, $invoiceId, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function storeInvoice(Model $billable, string $invoiceId, array $data = [], ?string $disk = null, ?string $path = null): string
    {
        $this->ensure(Capability::Invoices);

        return $this->inner()->storeInvoice($billable, $invoiceId, $data, $disk, $path);
    }
}
