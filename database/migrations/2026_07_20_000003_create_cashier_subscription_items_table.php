<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prices a subscription bills on.
 *
 * **`quantity` is nullable, and that is a statement about gateways rather than a convenience.**
 * Some carry no per-subscription quantity at all and cannot report one back, so NOT NULL forced
 * their drivers to either invent a value — billing a five-seat subscription as one — or refuse
 * to write the item row entirely, which left subscribedToPrice() permanently false. NULL means
 * "unknown / not applicable"; it is not the same answer as 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_subscription_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // A real constraint, not just a column and an index: an item without its
            // subscription is not a record of anything, so it goes when the parent goes.
            $table->foreignUuid('subscription_id')
                ->constrained('cashier_subscriptions')
                ->cascadeOnDelete();
            $table->string('provider_id')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('price');
            $table->integer('quantity')->nullable();
            $table->timestamps();

            // A subscription bills a given price once. The pair — not subscription_id alone —
            // because the table stays multi-item for drivers whose subscriptions carry several
            // distinct prices.
            $table->unique(['subscription_id', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_subscription_items');
    }
};
