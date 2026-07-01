<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Invoice;

use Isapp\CashierSupport\DTO\Invoice;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Renders an Invoice DTO to a PDF via spatie/laravel-pdf.
 *
 * Shared, provider-independent feature. The concrete PDF engine (Browsershot,
 * Chrome, ...) is the application's choice; this package does not pin one.
 */
class InvoiceRenderer
{
    /**
     * Build a PDF for the invoice. The returned PdfBuilder is Responsable and
     * can be returned from a controller, downloaded, or saved.
     *
     * @param  array<string, mixed>  $data  Extra view data (e.g. seller details).
     */
    public function render(Invoice $invoice, array $data = []): PdfBuilder
    {
        /** @var string $view */
        $view = config('cashier-support.invoices.view', 'cashier-support::invoice');

        /** @var string $format */
        $format = config('cashier-support.invoices.paper', 'a4');

        /** @var array<string, mixed> $seller */
        $seller = config('cashier-support.invoices.seller', []);

        return Pdf::view($view, array_merge(['invoice' => $invoice, 'seller' => $seller], $data))
            ->format($format);
    }
}
