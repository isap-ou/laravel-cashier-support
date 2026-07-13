<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price change that is scheduled but has not taken effect yet.
 *
 * Where a gateway defers a plan change to the end of the billing cycle, the
 * subscription keeps being billed on the OLD price — and the item row must keep
 * naming it, or the record would lie about what the customer pays. That left the
 * requested price with nowhere to live: a successful swap was indistinguishable
 * from no swap, and "you'll move to Pro on 1 Aug" could not be rendered.
 *
 * `next_price_starts_at` is nullable on its own: a gateway may schedule the
 * change without saying when it lands. Unknown date, not absent change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_subscriptions', function (Blueprint $table): void {
            $table->string('next_price')->nullable()->after('current_period_end');
            $table->timestamp('next_price_starts_at')->nullable()->after('next_price');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['next_price', 'next_price_starts_at']);
        });
    }
};
