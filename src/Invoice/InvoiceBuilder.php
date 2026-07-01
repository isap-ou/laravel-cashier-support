<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Invoice;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;

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

    private Currency $currency = Currency::EUR;

    private PaymentStatus $status = PaymentStatus::Succeeded;

    private ?CarbonImmutable $issuedAt = null;

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
     * Add a line. The amount is the line total in minor units (cents).
     */
    public function addLine(string $description, int $amount, int $quantity = 1): self
    {
        $this->lines[] = new InvoiceLine($description, $amount, $quantity);

        return $this;
    }

    /**
     * Build the invoice DTO, totalling the line amounts.
     */
    public function build(): Invoice
    {
        $total = array_sum(array_map(static fn (InvoiceLine $line): int => $line->amount, $this->lines));

        return new Invoice(
            id: $this->id,
            amount: $total,
            currency: $this->currency,
            status: $this->status,
            number: $this->number,
            lines: $this->lines,
            issuedAt: $this->issuedAt,
        );
    }
}
