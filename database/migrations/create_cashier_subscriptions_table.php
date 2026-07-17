<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `paused_at` is the instant the pause takes effect, not "paused since": tense tells the two
 * states apart, exactly as `ends_at` does for cancellation. A `paused_at` in the past means the
 * subscription is paused now; a `paused_at` in the future means the pause is scheduled and the
 * subscription is still serving until then (Models\Concerns\TracksPause). This mirrors Paddle,
 * which writes the real pause instant for an immediate pause and the scheduled effective_at for a
 * deferred one into the same column (Subscription.php:741).
 *
 * `resumes_at` is when collection auto-resumes — Stripe's name for it (pause_collection.resumes_at).
 * Nullable and independent: a pause need not name an end, and only some gateways report one back.
 */
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
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumes_at')->nullable();
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
