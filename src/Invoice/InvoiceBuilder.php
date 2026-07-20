<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Invoice;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
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
        if ($tax < 0) {
            throw new InvalidArgumentException("An invoice's tax cannot be negative, {$tax} given.");
        }

        $this->tax = $tax;

        return $this;
    }

    /**
     * The aggregate discount across the invoice, in minor units (cents).
     */
    public function discount(int $discount): self
    {
        if ($discount < 0) {
            throw new InvalidArgumentException(
                "An invoice's discount cannot be negative, {$discount} given. A negative discount is a surcharge — add it as a line."
            );
        }

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
     * any discount. The breakdown fields stay null when there is nothing to break down, so an
     * invoice with no VAT reports no breakdown rather than a row of zeros.
     *
     * **Tax may be stated in two places, and they have to agree.** `addLine(taxAmount: …)`
     * used to be accepted, stored on the DTO and rendered on the document while contributing
     * nothing to the total — so `addLine('Pro', 1000, taxAmount: 200)` produced an invoice
     * showing €2.00 of VAT on its line and a Total of €10.00. Not a rounding quibble: that
     * document is wrong for a VAT filing, and the caller did exactly what the API invited.
     *
     * So per-line tax now sums into the total. When `tax()` is ALSO called, the two must
     * match — two sources for one number, disagreeing, cannot be resolved by silently
     * preferring either, and whichever we picked would put a total on the document that its
     * own lines do not support.
     *
     * @throws InvalidArgumentException When an explicit tax contradicts the lines, or the
     *                                  discount drives the total below zero.
     */
    public function build(): Invoice
    {
        $subtotal = array_sum(array_map(static fn (InvoiceLine $line): int => $line->amount, $this->lines));

        $lineTax = array_sum(array_map(
            static fn (InvoiceLine $line): int => $line->taxAmount ?? 0,
            $this->lines
        ));

        if ($this->tax !== null && $lineTax > 0 && $this->tax !== $lineTax) {
            throw new InvalidArgumentException(
                "The invoice tax ({$this->tax}) does not match the sum of its lines' tax ({$lineTax}). "
                .'State it once — either per line, or as the aggregate.'
            );
        }

        $tax = $this->tax ?? ($lineTax > 0 ? $lineTax : null);
        $amount = $subtotal + ($tax ?? 0) - ($this->discount ?? 0);

        if ($amount < 0) {
            throw new InvalidArgumentException(
                "A discount of {$this->discount} makes the invoice total negative ({$amount}). "
                .'An invoice is not a refund; the discount cannot exceed what is being discounted.'
            );
        }

        $hasBreakdown = $tax !== null || $this->discount !== null;

        return new Invoice(
            id: $this->id,
            amount: $amount,
            // Fall back to the app's configured currency only when none was set, so make()
            // never touches config and an explicit currency() bypasses it entirely.
            currency: $this->currency ?? CurrencyCast::fromCode((string) config('cashier-support.currency')),
            status: $this->status,
            number: $this->number,
            lines: $this->lines,
            issuedAt: $this->issuedAt,
            subtotal: $hasBreakdown ? $subtotal : null,
            tax: $tax,
            discount: $this->discount,
        );
    }
}
