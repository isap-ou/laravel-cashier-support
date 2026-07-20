<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer identity a gateway assigns to a billable entity.
 *
 * A side table rather than a column on the app's own table, for the same reason
 * subscriptions, items and invoices are: a driver-named column forbids two
 * things structurally. A second driver would need a second column, and a reverse
 * lookup by customer id — which every order webhook needs — could only ever
 * search one billable class, so a Team could never be billed alongside a User.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('owner');
            $table->string('provider');
            $table->string('provider_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            // One gateway customer maps to exactly one billable...
            $table->unique(['provider', 'provider_id']);
            // ...and a billable has at most one identity per gateway.
            $table->unique(['owner_type', 'owner_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_customers');
    }
};
