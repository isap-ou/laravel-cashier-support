<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('owner');
            $table->string('type');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('status');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);

            // The columns Concerns\ManagesSubscriptions::subscriptions() filters on, in filter
            // order, so the query scopes on Models\Subscription land on an index rather than a
            // scan. #29 asks for (owner_type, owner_id, status) — Stripe's shape — but every
            // scope query arrives through subscriptions(), which always carries `provider`;
            // an index that skips it stops being useful after owner_id.
            $table->index(['owner_type', 'owner_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_subscriptions');
    }
};
