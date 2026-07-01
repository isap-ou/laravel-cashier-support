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
}
