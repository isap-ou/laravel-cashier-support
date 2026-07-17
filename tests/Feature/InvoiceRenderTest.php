<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Tests\TestCase;

class InvoiceRenderTest extends TestCase
{
    public function test_the_view_renders_the_vat_breakdown_and_per_line_tax(): void
    {
        $invoice = new Invoice(
            id: 'in_vat',
            amount: 1700,
            currency: Currency::EUR,
            status: PaymentStatus::Succeeded,
            number: '2026-001',
            lines: [
                new InvoiceLine('Pro plan', 1000, 1, unitAmount: 1000, taxAmount: 200, taxRate: 2000),
                new InvoiceLine('Add-on', 500, 2, unitAmount: 250, taxAmount: 100, taxRate: 2000),
            ],
            subtotal: 1500,
            tax: 300,
            discount: 100,
        );

        // The same view InvoiceRenderer feeds to spatie/laravel-pdf; rendering it to HTML
        // exercises the PDF content without a headless browser.
        $html = view('cashier-support::invoice', ['invoice' => $invoice, 'seller' => []])->render();

        // Footer breakdown rows.
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('EUR 15.00', $html);
        $this->assertStringContainsString('Tax', $html);
        $this->assertStringContainsString('EUR 3.00', $html);
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('-EUR 1.00', $html);

        // Per-line tax amount and rate.
        $this->assertStringContainsString('EUR 2.00', $html);
        $this->assertStringContainsString('(20%)', $html);
    }

    public function test_the_view_omits_breakdown_rows_when_absent(): void
    {
        $invoice = new Invoice(
            id: 'in_plain',
            amount: 1000,
            currency: Currency::EUR,
            status: PaymentStatus::Succeeded,
            lines: [new InvoiceLine('Pro plan', 1000)],
        );

        $html = view('cashier-support::invoice', ['invoice' => $invoice, 'seller' => []])->render();

        $this->assertStringNotContainsString('Subtotal', $html);
        $this->assertStringNotContainsString('Discount', $html);
        // The Total row is always present.
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('EUR 10.00', $html);
    }
}
