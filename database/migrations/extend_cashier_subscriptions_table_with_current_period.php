<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The period a subscription is paid through.
 *
 * `ends_at` says when access *stops*, and is only written on cancellation, so a
 * live subscription could not answer "when am I next billed?" — nor, after a
 * plan change that takes effect at cycle end, "when does the new plan start?"
 * (the same date).
 *
 * Both columns are nullable on purpose: a gateway may expose no billing cycle,
 * and NULL honestly means "unknown". They stay disjoint from `ends_at` — on
 * cancellation a driver sets `ends_at = current_period_end`, which is what the
 * customer paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_subscriptions', function (Blueprint $table): void {
            $table->timestamp('current_period_start')->nullable()->after('status');
            $table->timestamp('current_period_end')->nullable()->after('current_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['current_period_start', 'current_period_end']);
        });
    }
};
