<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;

/**
 * Abstract local, provider-independent invoice record.
 *
 * Invoices are generated locally by this package from stored payment and
 * subscription data (see Isapp\CashierSupport\Invoice\InvoiceBuilder), so this
 * model is shared across all providers.
 *
 * @property int $amount
 * @property Currency $currency
 * @property PaymentStatus $status
 * @property CarbonImmutable|null $issued_at
 */
abstract class Invoice extends Model
{
    protected $table = 'cashier_invoices';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency' => Currency::class,
            'status' => PaymentStatus::class,
            'issued_at' => 'immutable_datetime',
        ];
    }

    /**
     * The billable entity the invoice belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether the invoice has been paid.
     */
    public function paid(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }
}
