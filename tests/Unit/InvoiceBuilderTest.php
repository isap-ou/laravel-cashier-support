<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Invoice\InvoiceBuilder;
use Isapp\CashierSupport\Tests\TestCase;

class InvoiceBuilderTest extends TestCase
{
    public function test_it_totals_line_amounts(): void
    {
        $invoice = InvoiceBuilder::make()
            ->id('in_1')
            ->number('2026-001')
            ->currency(Currency::EUR)
            ->status(PaymentStatus::Succeeded)
            ->addLine('Pro plan', 1000)
            ->addLine('Add-on', 500, 2)
            ->build();

        $this->assertSame('in_1', $invoice->id);
        $this->assertSame(1500, $invoice->amount);
        $this->assertCount(2, $invoice->lines);
        $this->assertSame(Currency::EUR, $invoice->currency);
    }

    public function test_it_computes_subtotal_tax_and_discount(): void
    {
        $invoice = InvoiceBuilder::make()
            ->id('in_2')
            ->currency(Currency::EUR)
            ->addLine('Pro plan', 1000, 1, unitAmount: 1000, taxAmount: 200, taxRate: 2000)
            ->addLine('Add-on', 500, 2, unitAmount: 250, taxAmount: 100, taxRate: 2000)
            ->tax(300)
            ->discount(100)
            ->build();

        // subtotal = sum of line amounts; amount (total) = subtotal + tax - discount.
        $this->assertSame(1500, $invoice->subtotal);
        $this->assertSame(300, $invoice->tax);
        $this->assertSame(100, $invoice->discount);
        $this->assertSame(1700, $invoice->amount);

        $this->assertSame(1000, $invoice->lines[0]->unitAmount);
        $this->assertSame(200, $invoice->lines[0]->taxAmount);
        $this->assertSame(2000, $invoice->lines[0]->taxRate);
    }

    public function test_a_no_vat_invoice_reports_no_breakdown(): void
    {
        // Without tax()/discount(), the breakdown stays null so the view shows only the Total
        // rather than rows of zeros.
        $invoice = InvoiceBuilder::make()
            ->id('in_3')
            ->currency(Currency::EUR)
            ->addLine('Pro plan', 1000)
            ->build();

        $this->assertSame(1000, $invoice->amount);
        $this->assertNull($invoice->subtotal);
        $this->assertNull($invoice->tax);
        $this->assertNull($invoice->discount);
    }
}
