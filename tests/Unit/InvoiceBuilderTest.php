<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use InvalidArgumentException;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Invoice\InvoiceBuilder;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;

class InvoiceBuilderTest extends TestCase
{
    public function test_it_totals_line_amounts(): void
    {
        $invoice = InvoiceBuilder::make()
            ->id('in_1')
            ->number('2026-001')
            ->currency(new Currency('EUR'))
            ->status(PaymentStatus::Succeeded)
            ->addLine('Pro plan', 1000)
            ->addLine('Add-on', 500, 2)
            ->build();

        $this->assertSame('in_1', $invoice->id);
        $this->assertSame(1500, $invoice->amount);
        $this->assertCount(2, $invoice->lines);
        $this->assertSame('EUR', $invoice->currency->getCode());
    }

    public function test_it_computes_subtotal_tax_and_discount(): void
    {
        $invoice = InvoiceBuilder::make()
            ->id('in_2')
            ->currency(new Currency('EUR'))
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
            ->currency(new Currency('EUR'))
            ->addLine('Pro plan', 1000)
            ->build();

        $this->assertSame(1000, $invoice->amount);
        $this->assertNull($invoice->subtotal);
        $this->assertNull($invoice->tax);
        $this->assertNull($invoice->discount);
    }

    public function test_per_line_tax_reaches_the_total(): void
    {
        // The defect: taxAmount was accepted on a line, stored on the DTO, rendered on the
        // PDF — and never added to anything. An invoice showing a line with €2.00 VAT and a
        // Total of €10.00 is not a rounding quibble; it is a document that is wrong for a VAT
        // filing, produced by a caller who did everything the API invited them to do.
        $invoice = InvoiceBuilder::make()
            ->id('in_tax')
            ->currency(new Currency('EUR'))
            ->addLine('Pro plan', 1000, taxAmount: 200)
            ->addLine('Add-on', 500, taxAmount: 100)
            ->build();

        $this->assertSame(1500, $invoice->subtotal);
        $this->assertSame(300, $invoice->tax);
        $this->assertSame(1800, $invoice->amount);
    }

    public function test_an_explicit_tax_that_contradicts_the_lines_is_refused(): void
    {
        // Two sources for one number, disagreeing. Silently preferring either would put a
        // total on the document that its own lines do not support — a programmer error, so
        // InvalidArgumentException per .claude/rules/exceptions.md, not a billing failure.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');

        InvoiceBuilder::make()
            ->id('in_conflict')
            ->currency(new Currency('EUR'))
            ->addLine('Pro plan', 1000, taxAmount: 200)
            ->tax(999)
            ->build();
    }

    public function test_an_explicit_tax_agreeing_with_the_lines_is_fine(): void
    {
        $invoice = InvoiceBuilder::make()
            ->id('in_agree')
            ->currency(new Currency('EUR'))
            ->addLine('Pro plan', 1000, taxAmount: 200)
            ->tax(200)
            ->build();

        $this->assertSame(1200, $invoice->amount);
    }

    public function test_a_discount_may_not_exceed_what_is_being_discounted(): void
    {
        // A negative total is not an invoice. Nothing stopped one being built.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('negative');

        InvoiceBuilder::make()
            ->id('in_neg')
            ->currency(new Currency('EUR'))
            ->addLine('Pro plan', 1000)
            ->discount(5000)
            ->build();
    }

    public function test_negative_tax_and_discount_are_refused(): void
    {
        foreach (['tax', 'discount'] as $setter) {
            try {
                InvoiceBuilder::make()
                    ->id('in_'.$setter)
                    ->currency(new Currency('EUR'))
                    ->addLine('Pro plan', 1000)
                    ->{$setter}(-1)
                    ->build();
                $this->fail("Expected a negative {$setter} to be refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('negative', $e->getMessage());
            }
        }
    }
}
