<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local invoice record.
 *
 * **The subscription link and the service period.** A renewal invoice was previously
 * unlinkable to either, so an app could not build a billing history — and an invoice that
 * cannot state its service period is not a usable invoice at all. `subscription_id` references
 * the *local* cashier_subscriptions uuid rather than a provider id, because that record is the
 * one this package owns. All of them are nullable: a one-off charge pays for no subscription
 * and no cycle.
 *
 * The constraint is `nullOnDelete`, deliberately unlike the items table's cascade. An invoice
 * is a financial record: it must outlive the subscription it paid for, and losing billing
 * history because a subscription row was deleted would be the worse failure by far.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('owner');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->foreignUuid('subscription_id')
                ->nullable()
                ->constrained('cashier_subscriptions')
                ->nullOnDelete();
            $table->string('number')->nullable();
            $table->bigInteger('amount');
            // Optional money breakdown, all in minor units. amount stays the canonical total;
            // when present, subtotal + tax - discount reconciles to it. Nullable because a
            // gateway may report only the total (see DTO\Invoice).
            $table->bigInteger('subtotal')->nullable();
            $table->bigInteger('tax')->nullable();
            $table->bigInteger('discount')->nullable();
            // Persisted line items (DTO\InvoiceLine shape). Nullable because a gateway may
            // report a total with no breakdown; the renderer then falls back to caller-supplied
            // display lines.
            $table->json('lines')->nullable();
            $table->string('currency', 3);
            $table->string('status');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('billing_reason')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);

            // Gateway\ManagesLocalInvoices::invoiceQuery() filters on owner + provider and then
            // sorts by issued_at. morphs() indexes the owner pair, but the sort was a filesort
            // over every invoice a billable had ever had — linear degradation on exactly the
            // "show me my billing history" endpoint this table exists for.
            $table->index(['owner_type', 'owner_id', 'provider', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_invoices');
    }
};
