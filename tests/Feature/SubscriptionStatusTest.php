<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A subscription whose payment never arrived is still a subscription.
 *
 * The enum mirrored six of Stripe's eight statuses, and the model casts the
 * column through BackedEnum::from() — so a row a driver wrote as `unpaid` or
 * `incomplete_expired` crashed on read instead of reporting a lost customer.
 */
class SubscriptionStatusTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    /**
     * The cast resolves through from() on the way in as well as out, so the row
     * cannot be seeded through the model — writing the string would throw before
     * the read under test happens. Insert raw, exactly as a driver's row arrives.
     */
    private function seedStatus(string $status): void
    {
        DB::table('cashier_subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[DataProvider('dunningStatuses')]
    public function test_a_dunning_status_row_reads_back_as_its_enum(string $stored): void
    {
        $this->seedStatus($stored);

        $status = ConcreteSubscription::first()?->status;

        $this->assertInstanceOf(SubscriptionStatus::class, $status);
        $this->assertSame($stored, $status->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dunningStatuses(): array
    {
        return [
            'dunning exhausted' => ['unpaid'],
            'initial payment never completed' => ['incomplete_expired'],
        ];
    }
}
