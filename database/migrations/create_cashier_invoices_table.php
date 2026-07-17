<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('owner');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('number')->nullable();
            $table->bigInteger('amount');
            // Optional money breakdown, all in minor units. amount stays the canonical total;
            // when present, subtotal + tax - discount reconciles to it. Nullable because a
            // gateway may report only the total (see DTO\Invoice).
            $table->bigInteger('subtotal')->nullable();
            $table->bigInteger('tax')->nullable();
            $table->bigInteger('discount')->nullable();
            // Persisted line items (DTO\InvoiceLine shape). Null on legacy rows; the gateway
            // still renders caller-supplied display lines when this is absent.
            $table->json('lines')->nullable();
            $table->string('currency', 3);
            $table->string('status');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_invoices');
    }
};
