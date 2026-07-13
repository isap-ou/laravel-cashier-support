<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity is a gateway-specific attribute, not a universal one.
 *
 * Some gateways carry no per-subscription quantity at all and cannot report one
 * back, so NOT NULL forced their drivers to either invent a value — billing a
 * five-seat subscription as one — or refuse to write the item row entirely,
 * which left subscribedToPrice() permanently false.
 *
 * NULL now means "unknown / not applicable". Rows written before this migration
 * keep their existing value, so a stored 1 can no longer be told apart from a
 * defaulted one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_subscription_items', function (Blueprint $table): void {
            $table->integer('quantity')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Rows written while quantity was nullable hold the truth "unknown".
        // Going back to NOT NULL cannot express that, so it has to be flattened
        // to 1 — lossy, and the reason this direction is not symmetric.
        DB::table('cashier_subscription_items')->whereNull('quantity')->update(['quantity' => 1]);

        Schema::table('cashier_subscription_items', function (Blueprint $table): void {
            $table->integer('quantity')->default(1)->change();
        });
    }
};
