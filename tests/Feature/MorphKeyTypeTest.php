<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * The package's tables are owned polymorphically, so the owner key must follow
 * the app's own primary keys — a UUID-keyed User is as ordinary as an int-keyed
 * one, and a billing table that only accepts one of them would be unusable.
 *
 * morphs() already resolves that: it delegates to uuidMorphs()/ulidMorphs() when
 * the app declares its morph key type. This pins that behaviour, so a future
 * migration cannot quietly hardcode bigint and lock UUID apps out.
 */
class MorphKeyTypeTest extends TestCase
{
    protected function tearDown(): void
    {
        SchemaBuilder::defaultMorphKeyType('int');

        parent::tearDown();
    }

    private function ownerKeyType(string $table): string
    {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'owner_id');

        return strtolower((string) ($column['type_name'] ?? ''));
    }

    public function test_the_owner_key_follows_the_apps_morph_key_type(): void
    {
        // Default: integer keys.
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        foreach (['cashier_customers', 'cashier_subscriptions', 'cashier_invoices'] as $table) {
            $this->assertStringContainsString('int', $this->ownerKeyType($table), $table);
        }
    }

    public function test_a_uuid_keyed_app_gets_uuid_owner_keys(): void
    {
        // What an app with UUID primary keys declares in its service provider.
        Schema::morphUsingUuids();

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        foreach (['cashier_customers', 'cashier_subscriptions', 'cashier_invoices'] as $table) {
            $this->assertStringContainsString('char', $this->ownerKeyType($table), $table);
        }
    }
}
