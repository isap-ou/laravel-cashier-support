<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties an invoice to the subscription and the cycle it paid for.
 *
 * A renewal invoice was previously unlinkable to either: an app could not build
 * a billing history, and this package renders these invoices to PDF — an invoice
 * that cannot state its service period is not a usable invoice.
 *
 * `subscription_id` references the *local* cashier_subscriptions uuid rather
 * than a provider id, because that record is the one this package owns. All
 * columns are nullable: a one-off charge pays for no subscription and no cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_invoices', function (Blueprint $table): void {
            $table->uuid('subscription_id')->nullable()->after('provider_id')->index();
            $table->timestamp('period_start')->nullable()->after('issued_at');
            $table->timestamp('period_end')->nullable()->after('period_start');
            $table->string('billing_reason')->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_invoices', function (Blueprint $table): void {
            // The index has to go before the column it covers — SQLite refuses
            // to drop a column an index still references.
            $table->dropIndex(['subscription_id']);
            $table->dropColumn(['subscription_id', 'period_start', 'period_end', 'billing_reason']);
        });
    }
};
