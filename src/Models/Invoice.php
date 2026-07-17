<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Isapp\CashierSupport\Casts\CurrencyEloquentCast;
use Isapp\CashierSupport\Enums\BillingReason;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Money\Currency;

/**
 * Abstract local, provider-independent invoice record.
 *
 * Invoices are generated locally by this package from stored payment and
 * subscription data (see Isapp\CashierSupport\Invoice\InvoiceBuilder), so this
 * model is shared across all providers.
 *
 * @property int $amount
 * @property int|null $subtotal
 * @property int|null $tax
 * @property int|null $discount
 * @property array<int, array<string, mixed>>|null $lines
 * @property Currency $currency
 * @property PaymentStatus $status
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property BillingReason|null $billing_reason
 */
abstract class Invoice extends Model
{
    use HasUuids;

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
            'subtotal' => 'integer',
            'tax' => 'integer',
            'discount' => 'integer',
            'lines' => 'array',
            'currency' => CurrencyEloquentCast::class,
            'status' => PaymentStatus::class,
            'issued_at' => 'immutable_datetime',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'billing_reason' => BillingReason::class,
        ];
    }

    /**
     * The subscription this invoice paid for, if any.
     *
     * Resolves the concrete class through the per-driver registry from this
     * row's provider column — the same technique Subscription::items() uses, so
     * lazy access on a hydrated record is driver-exact. Eager loading resolves
     * the relation on an unhydrated model, which has no provider yet and so
     * falls back to the default driver's registry entry; that is inherited from
     * items() and applies equally here. Null for a one-off charge, which no
     * subscription drives.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        $provider = $this->getAttribute('provider');

        return $this->belongsTo(Cashier::subscriptionModel(is_string($provider) ? $provider : null), 'subscription_id');
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
