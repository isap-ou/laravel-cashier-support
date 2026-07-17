<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\Exceptions\CashierException;

/**
 * Renders an Invoice DTO to document bytes (typically a PDF).
 *
 * This package ships no implementation and pins no engine: a driver supplies its own
 * renderer through Contracts\RendersInvoices, the way it supplies webhook handling. The
 * return is raw bytes rather than a PDF-builder type so that nothing about the engine
 * (Dompdf, Browsershot, an external service) leaks into the contract — from bytes, the
 * gateway derives both a download response and a saved file. Mirrors the reference's
 * Contracts\InvoiceRenderer::render(): string (vendor/laravel/cashier).
 */
interface InvoiceRenderer
{
    /**
     * Render the invoice and return its bytes.
     *
     * @param  array<string, mixed>  $data  Extra render data (display lines, seller details, ...).
     *
     * @throws CashierException When the invoice cannot be rendered.
     */
    public function render(Invoice $invoice, array $data = []): string;
}
