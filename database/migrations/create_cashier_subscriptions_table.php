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
            $table->id();
            $table->morphs('owner');
            $table->string('name');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('status');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_subscriptions');
    }
};
