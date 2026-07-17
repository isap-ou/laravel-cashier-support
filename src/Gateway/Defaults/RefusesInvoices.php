<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Gateway\Defaults;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contracts\InvoiceOperations, refused.
 *
 * The one whose refusals are least likely to survive: invoices are generated locally from
 * data this package already stores, so a driver mixes in Gateway\ManagesLocalInvoices and
 * all three of these are overridden at once. That pairing is the reason BaseGateway is a
 * class — two traits defining invoices() in the same class is a fatal collision, while a
 * trait beating an inherited default is just how PHP resolves methods.
 *
 * Composed into Gateway\BaseGateway — see its docblock before using this directly.
 */
trait RefusesInvoices
{
    /**
     * @return array<int, Invoice>
     */
    public function invoices(Model $billable, array $parameters = []): array
    {
        throw UnsupportedOperationException::forCapability(Capability::Invoices);
    }

    public function findInvoice(Model $billable, string $invoiceId): ?Invoice
    {
        throw UnsupportedOperationException::forCapability(Capability::Invoices);
    }

    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response
    {
        throw UnsupportedOperationException::forCapability(Capability::Invoices);
    }
}
