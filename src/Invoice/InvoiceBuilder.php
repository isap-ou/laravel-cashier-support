<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Invoice;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Casts\CurrencyCast;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Money\Currency;

/**
 * Fluent builder that assembles an Invoice DTO from local data.
 *
 * This is a shared, provider-independent feature: it only aggregates stored
 * line data into a DTO and totals it. It performs no billing logic and no
 * network calls.
 */
class InvoiceBuilder
{
    private string $id = '';

    private ?string $number = null;

    private ?Currency $currency = null;

    private PaymentStatus $status = PaymentStatus::Succeeded;

    private ?CarbonImmutable $issuedAt = null;

    private ?int $tax = null;

    private ?int $discount = null;

    /**
     * @var array<int, InvoiceLine>
     */
    private array $lines = [];

    public static function make(): self
    {
        return new self;
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function number(?string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function currency(Currency $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function status(PaymentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function issuedAt(CarbonImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    /**
     * The aggregate tax across the invoice, in minor units (cents).
     */
    public function tax(int $tax): self
    {
        $this->tax = $tax;

        return $this;
    }

    /**
     * The aggregate discount across the invoice, in minor units (cents).
     */
    public function discount(int $discount): self
    {
        $this->discount = $discount;

        return $this;
    }

    /**
     * Add a line. All amounts are in minor units (cents); $taxRate is in basis points
     * (2000 = 20.00%).
     */
    public function addLine(
        string $description,
        int $amount,
        int $quantity = 1,
        ?int $unitAmount = null,
        ?int $taxAmount = null,
        ?int $taxRate = null,
    ): self {
        $this->lines[] = new InvoiceLine($description, $amount, $quantity, $unitAmount, $taxAmount, $taxRate);

        return $this;
    }

    /**
     * Build the invoice DTO. amount (the total) is the sum of line amounts, plus any tax, less
     * any discount. The breakdown fields stay null unless tax()/discount() were called, so an
     * invoice with no VAT reports no breakdown rather than a row of zeros.
     */
    public function build(): Invoice
    {
        $subtotal = array_sum(array_map(static fn (InvoiceLine $line): int => $line->amount, $this->lines));
        $hasBreakdown = $this->tax !== null || $this->discount !== null;

        return new Invoice(
            id: $this->id,
            amount: $subtotal + ($this->tax ?? 0) - ($this->discount ?? 0),
            // Fall back to the app's configured currency only when none was set, so make()
            // never touches config and an explicit currency() bypasses it entirely.
            currency: $this->currency ?? CurrencyCast::fromCode((string) config('cashier-support.currency')),
            status: $this->status,
            number: $this->number,
            lines: $this->lines,
            issuedAt: $this->issuedAt,
            subtotal: $hasBreakdown ? $subtotal : null,
            tax: $this->tax,
            discount: $this->discount,
        );
    }
}
