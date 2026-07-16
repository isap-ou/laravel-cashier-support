<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Tests\Fixtures\RecordingBillableListener;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * An event that crosses a queue carries its billable by identity, not by value.
 *
 * Every event here holds a `Model $billable`. Serialized naively, the whole row
 * as it looked at dispatch time goes into the queue payload, and the listener —
 * `SubscriptionCreated` / `PaymentSucceeded` listeners are what grant access —
 * works from that snapshot however long it sat in the queue. A webhook that
 * lands in between, a status change, a cancellation: none of it reaches the
 * job. `SerializesModels` replaces the model with a ModelIdentifier and
 * re-fetches it on the other side, so the listener sees the row as it IS.
 */
class EventSerializationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // Mirrors the framework's own jobs table stub:
        // vendor/laravel/framework/src/Illuminate/Queue/Console/stubs/jobs.stub
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingBillableListener::reset();
    }

    private function user(string $name): User
    {
        return User::create(['name' => $name]);
    }

    private function subscriptionDto(): Subscription
    {
        return new Subscription(
            id: 'sub_ext',
            type: 'default',
            status: SubscriptionStatus::Active,
        );
    }

    /**
     * The queued job's payload must carry an identity reference, not the row.
     */
    public function test_a_queued_listener_payload_does_not_contain_the_billable_attributes(): void
    {
        $user = $this->user('Attribute Canary');

        Event::listen(SubscriptionCreated::class, RecordingBillableListener::class);

        event(new SubscriptionCreated($user, $this->subscriptionDto()));

        $payload = DB::table('jobs')->value('payload');

        $this->assertIsString($payload);
        $this->assertStringNotContainsString(
            'Attribute Canary',
            $payload,
            'The queue payload contains the billable\'s attributes — the model was serialized by value.'
        );
        $this->assertStringContainsString(
            'ModelIdentifier',
            $payload,
            'The queue payload does not carry a ModelIdentifier — the billable is not referenced by identity.'
        );
    }

    /**
     * And the job re-fetches, so a row that moved on is seen as it is now.
     */
    public function test_a_queued_listener_refetches_the_billable_rather_than_deserializing_a_snapshot(): void
    {
        $user = $this->user('Name At Dispatch');

        Event::listen(SubscriptionCreated::class, RecordingBillableListener::class);

        event(new SubscriptionCreated($user, $this->subscriptionDto()));

        // The world moves on while the job waits in the queue.
        DB::table('users')->where('id', $user->getKey())->update(['name' => 'Name At Run Time']);

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])->run();

        $this->assertTrue(
            RecordingBillableListener::$seenModel,
            'The listener did not receive a User model.'
        );
        $this->assertSame(
            'Name At Run Time',
            RecordingBillableListener::$seenName,
            'The listener saw a stale snapshot instead of re-fetching the billable.'
        );
    }

    /**
     * Dispatchable's static dispatch() reaches the listeners.
     */
    public function test_an_event_can_be_dispatched_statically(): void
    {
        $user = $this->user('Static Dispatch');

        $seen = null;

        Event::listen(SubscriptionCreated::class, function (SubscriptionCreated $event) use (&$seen): void {
            $seen = $event->billable->getAttribute('name');
        });

        SubscriptionCreated::dispatch($user, $this->subscriptionDto());

        $this->assertSame('Static Dispatch', $seen);
    }

    /**
     * A DTO-only event has no model to reference, and must survive the round
     * trip unchanged rather than be mangled by the identity substitution.
     */
    public function test_a_dto_only_event_survives_serialization(): void
    {
        $event = new WebhookReceived(new WebhookPayload(
            event: WebhookEvent::PaymentSucceeded,
            id: 'evt_1',
            data: ['order_id' => 'ord_1'],
        ));

        /** @var WebhookReceived $restored */
        $restored = unserialize(serialize($event));

        $this->assertSame(WebhookEvent::PaymentSucceeded, $restored->payload->event);
        $this->assertSame('evt_1', $restored->payload->id);
        $this->assertSame(['order_id' => 'ord_1'], $restored->payload->data);
    }
}
