<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Tests\Fixtures\QueuedEventProbe;
use Isapp\CashierSupport\Tests\Fixtures\RecordingBillableListener;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

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
     * The two tests above prove the mechanism, on one event. This proves the
     * reach: every event that carries a billable is wired the same way.
     *
     * Swept over src/Events/ rather than listed — inclusion by default, as
     * ExceptionBoundaryTest sweeps the contracts. An event added later is
     * covered the moment it exists, and an event carrying a payload this
     * sweep cannot build fails loudly instead of quietly opting out.
     */
    public function test_every_event_carrying_a_billable_crosses_a_queue_by_identity(): void
    {
        $swept = [];

        foreach ($this->eventClasses() as $class) {
            if (! $this->carriesBillable($class)) {
                continue;
            }

            DB::table('jobs')->delete();
            $user = $this->user('Attribute Canary');

            Event::listen($class, QueuedEventProbe::class);
            event($this->makeEvent($class, $user));

            $payload = DB::table('jobs')->value('payload');

            $this->assertIsString($payload, "[{$class}] did not reach the queue at all.");
            $this->assertStringNotContainsString(
                'Attribute Canary',
                $payload,
                "[{$class}] serialized the billable by value — its listener would work from a "
                .'stale snapshot. It is missing SerializesModels.',
            );
            $this->assertStringContainsString(
                'ModelIdentifier',
                $payload,
                "[{$class}] does not carry its billable by identity.",
            );

            DB::table('users')->where('id', $user->getKey())->delete();
            $swept[] = $class;
        }

        $this->assertNotEmpty($swept, 'The sweep found no event carrying a billable — it is not looking where it thinks.');
    }

    /**
     * Every event class in the package, found rather than enumerated.
     *
     * @return list<class-string>
     */
    private function eventClasses(): array
    {
        $files = glob(dirname(__DIR__, 2).'/src/Events/*.php') ?: [];

        $this->assertNotEmpty($files, 'No event classes found — the glob is wrong.');

        /** @var list<class-string> */
        return array_map(
            static fn (string $file): string => 'Isapp\\CashierSupport\\Events\\'.basename($file, '.php'),
            $files,
        );
    }

    /**
     * Whether the event holds an Eloquent model, i.e. whether SerializesModels
     * is load-bearing for it rather than merely present.
     *
     * @param  class-string  $class
     */
    private function carriesBillable(string $class): bool
    {
        foreach ($this->constructorParameters($class) as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_a($type->getName(), Model::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build an event from its constructor's types, so the sweep does not need a
     * hand-written case per class.
     *
     * @param  class-string  $class
     */
    private function makeEvent(string $class, User $user): object
    {
        $arguments = [];

        foreach ($this->constructorParameters($class) as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                $this->fail("[{$class}] parameter \${$parameter->getName()} has no single named type.");
            }

            $arguments[] = is_a($type->getName(), Model::class, true)
                ? $user
                : $this->fixtureFor($type->getName(), $class, $parameter->getName());
        }

        return new $class(...$arguments);
    }

    /**
     * @param  class-string  $class
     * @return list<ReflectionParameter>
     */
    private function constructorParameters(string $class): array
    {
        return (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
    }

    /**
     * A stand-in value for one constructor argument.
     *
     * An unknown type fails the sweep on purpose: a new event must say how it is
     * built here, rather than slipping past unchecked.
     */
    private function fixtureFor(string $type, string $class, string $parameter): mixed
    {
        return match ($type) {
            Subscription::class => $this->subscriptionDto(),
            Payment::class => $this->paymentDto(),
            Refund::class => $this->refundDto(),
            Invoice::class => $this->invoiceDto(),
            WebhookPayload::class => $this->webhookPayload(),
            default => $this->fail(
                "[{$class}] takes a {$type} \${$parameter}, and this sweep has no fixture for it. "
                .'Add one — an event must not escape the sweep by carrying an unfamiliar payload.'
            ),
        };
    }

    private function paymentDto(): Payment
    {
        return new Payment(
            id: 'pay_ext',
            amount: 1000,
            currency: Currency::EUR,
            status: PaymentStatus::Succeeded,
        );
    }

    private function refundDto(): Refund
    {
        return new Refund(
            id: 'ref_ext',
            paymentId: 'pay_ext',
            amount: 1000,
            currency: Currency::EUR,
        );
    }

    private function invoiceDto(): Invoice
    {
        return new Invoice(
            id: 'inv_ext',
            amount: 1000,
            currency: Currency::EUR,
            status: PaymentStatus::Succeeded,
        );
    }

    private function webhookPayload(): WebhookPayload
    {
        return new WebhookPayload(
            event: WebhookEvent::PaymentSucceeded,
            id: 'evt_1',
            data: ['order_id' => 'ord_1'],
        );
    }

    /**
     * A DTO-only event has no model to reference. This does not prove the traits
     * do anything — it pins that they do no HARM: the identity substitution must
     * leave a payload that has no model in it alone.
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
