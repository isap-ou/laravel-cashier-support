<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription, as this package records it locally.
 *
 * Four groups of columns, each with a reason worth keeping:
 *
 * **The paid-through period.** `ends_at` says when access *stops* and is only written on
 * cancellation, so a live subscription could not answer "when am I next billed?" — nor, after
 * a plan change that takes effect at cycle end, "when does the new plan start?" (the same
 * date). `current_period_start`/`_end` answer both. Nullable on purpose: a gateway may expose
 * no billing cycle, and NULL honestly means "unknown". They stay disjoint from `ends_at` — on
 * cancellation a driver sets `ends_at = current_period_end`, which is what the customer paid
 * for.
 *
 * **A scheduled price change.** Where a gateway defers a plan change to the end of the cycle,
 * the subscription keeps being billed on the OLD price, and the item row must keep naming it
 * or the record would lie about what the customer pays. That left the requested price with
 * nowhere to live: a successful swap was indistinguishable from no swap, and "you'll move to
 * Pro on 1 Aug" could not be rendered. `next_price_starts_at` is nullable on its own — a
 * gateway may schedule the change without saying when it lands. Unknown date, not absent
 * change.
 *
 * **The pause pair.** `paused_at` is the instant the pause takes effect, not "paused since":
 * tense tells the two states apart, exactly as `ends_at` does for cancellation. A `paused_at`
 * in the past means paused now; one in the future means the pause is scheduled and the
 * subscription is still serving until then (Models\Concerns\TracksPause). This mirrors Paddle,
 * which writes the real pause instant for an immediate pause and the scheduled effective_at
 * for a deferred one into the same column (Subscription.php:741). `resumes_at` is when
 * collection auto-resumes — Stripe's name for it (pause_collection.resumes_at) — nullable and
 * independent: a pause need not name an end, and only some gateways report one back.
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
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->string('next_price')->nullable();
            $table->timestamp('next_price_starts_at')->nullable();
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
