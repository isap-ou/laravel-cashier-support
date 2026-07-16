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
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Events\SubscriptionCreated;
use Isapp\CashierSupport\Events\WebhookHandled;
use Isapp\CashierSupport\Events\WebhookReceived;
use Isapp\CashierSupport\Tests\Fixtures\QueuedEventProbe;
use Isapp\CashierSupport\Tests\Fixtures\RecordingBillableListener;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * covered the moment it exists, and one this sweep cannot classify fails
     * loudly instead of quietly opting out. Note what that costs: the exit is
     * closed by carriesBillable() failing on anything it does not recognise,
     * not by cleverness — the first version of this sweep just returned false
     * for an unfamiliar type, and a union-typed billable sailed through green.
     *
     * It pins SerializesModels only. Dispatchable is asserted once, below, on
     * SubscriptionCreated — the payload says nothing about it.
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
     * Every parameter must be classifiable, and an unfamiliar type fails here
     * rather than returning false: "no billable" and "I could not tell" look
     * identical from outside, and the second one is how an event slips out of
     * the sweep while the suite stays green. A union-typed billable and an
     * Eloquent Collection both used to take that exit.
     *
     * @param  class-string  $class
     */
    private function carriesBillable(string $class): bool
    {
        $carries = false;

        foreach ($this->constructorParameters($class) as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                $this->fail(
                    "[{$class}] parameter \${$parameter->getName()} is not a single named type. "
                    .'This sweep cannot tell whether it carries a billable, so it must not guess — '
                    .'classify it here.'
                );
            }

            if (is_a($type->getName(), Model::class, true)) {
                $carries = true;

                continue;
            }

            if (! $this->hasFixtureFor($type->getName(), $class)) {
                $this->fail(
                    "[{$class}] parameter \${$parameter->getName()} is a {$type->getName()}, which "
                    .'this sweep does not know. If it can carry an Eloquent model — a Collection, '
                    .'say — then SerializesModels is load-bearing and this event must be swept. '
                    .'Add a fixture either way, so that skipping is a decision and not an accident.'
                );
            }
        }

        return $carries;
    }

    /**
     * The types this sweep can stand in for. Kept beside fixtureFor() on purpose:
     * the two must agree, or an event skips for a reason nobody chose.
     */
    private function hasFixtureFor(string $type, string $class): bool
    {
        // `array` is allowed for the two webhook events ONLY, and the narrowness is the
        // point. Their payload is json_decode output: it holds scalars and arrays and
        // can never hold an Eloquent model, so SerializesModels is not load-bearing for
        // it. That reasoning is about those events, not about the type — a bare `array`
        // is exactly the "I could not tell" this sweep exists to refuse, and an
        // `array $invoices` full of models would take the exit while the suite stayed
        // green. Keyed by type alone, this entry would have reopened the hole that
        // carriesBillable()'s fail-loudly rule was written to close.
        if ($type === 'array') {
            return in_array($class, [WebhookReceived::class, WebhookHandled::class], true);
        }

        // `string` is allowed for ANY event, and the difference from `array` above is not
        // laziness. That entry is narrow because an array's contents are unknowable from
        // the type — `array $invoices` could be full of models. A string's are not: PHP
        // cannot put an object inside one, at any nesting, ever. So SerializesModels can
        // never be load-bearing for a string parameter, and this exit cannot widen into
        // the hole the array rule was written to close.
        if ($type === 'string') {
            return true;
        }

        return in_array($type, [
            Subscription::class,
            Payment::class,
            Refund::class,
            Invoice::class,
        ], true);
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
            'array' => $this->webhookBody(),
            'string' => 'revolut',
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

    /**
     * A provider's decoded webhook body, of the shape json_decode actually returns.
     *
     * @return array<string, mixed>
     */
    private function webhookBody(): array
    {
        return ['event' => 'ORDER_COMPLETED', 'order_id' => 'ord_1', 'amount' => 1000];
    }

    /**
     * A body-only event has no model to reference. This does not prove the traits
     * do anything — it pins that they do no HARM: the identity substitution must
     * leave a payload that has no model in it alone.
     *
     * Both of them, not one: the sweep skips these two by design, so this is the
     * only thing that touches them at all.
     *
     * The body is nested and mixed-type on purpose. It is the provider's, not ours,
     * so nothing constrains its shape — and a queued listener is exactly where an
     * over-clever serializer would flatten it.
     *
     * @param  class-string  $class
     */
    #[DataProvider('rawBodyEvents')]
    public function test_a_raw_body_event_survives_serialization(string $class): void
    {
        $body = [
            'event' => 'DISPUTE_ACTION_REQUIRED',
            'id' => 'dis_1',
            'nested' => ['reason' => 'fraud', 'amount' => 1000, 'evidence_due' => null],
            'flagged' => true,
        ];

        $event = new $class('revolut', $body);

        /** @var WebhookReceived|WebhookHandled $restored */
        $restored = unserialize(serialize($event));

        $this->assertSame($body, $restored->payload);
        // The discriminator has to survive too: with one shared event class per driver,
        // it is the only thing telling a queued listener whose body this is.
        $this->assertSame('revolut', $restored->provider);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function rawBodyEvents(): array
    {
        return [
            'WebhookReceived' => [WebhookReceived::class],
            'WebhookHandled' => [WebhookHandled::class],
        ];
    }
}
