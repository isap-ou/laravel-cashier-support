<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #29: every predicate on Models\Subscription is now also a query, and this is the class that
 * says the two agree.
 *
 * The issue's acceptance is "each predicate has a matching scope, and both agree on the same
 * fixture set". Agreement is asserted over the WHOLE fixture space rather than a sample: all 8
 * statuses × {no ends_at, past, future} × {no trial_ends_at, past, future} × {no paused_at, past,
 * future} = 216 rows, seeded once, and every scope compared against its predicate row by row. A
 * sampled matrix would pass while the one state nobody thought of disagreed — and the states
 * nobody thinks of are exactly where our predicates diverge from the references (a status-only
 * trial, a canceled row with no date), which is why the space is enumerated instead of chosen.
 * The paused_at axis (#30) is what pins the pause scopes' null-explicit negations: a nullable
 * column compared directly would drop its NULL rows from both a scope and its negation, and only
 * enumerating the null combination catches it.
 *
 * The comparison runs the predicate in PHP over the same rows the scope filtered in SQL, so
 * neither side is hand-written. A hand-written expectation would be a third body to keep in
 * sync, and #29 exists because two bodies already drift.
 */
class SubscriptionScopeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::extend('fake', fn () => new FakeGateway([Capability::Subscriptions]));
        Cashier::useModels('fake', [
            'subscription' => ConcreteSubscription::class,
            'subscription_item' => ConcreteSubscriptionItem::class,
        ]);
    }

    // ---------------------------------------------------------------------
    // The fixture space
    // ---------------------------------------------------------------------

    /**
     * Every scope, paired with the predicate it must agree with.
     *
     * The four negations have no predicate of their own — PHP has `!` and a query builder does
     * not — so they are paired with the negation of theirs. That asymmetry is the reason they
     * exist at all: a scope cannot be negated from outside, so `notOnTrial()` is the only way
     * to express the complement inside a whereHas() group.
     *
     * @return array<string, array{string, callable(ConcreteSubscription): bool}>
     */
    public static function scopes(): array
    {
        return [
            'valid' => ['valid', fn (ConcreteSubscription $s): bool => $s->valid()],
            'active' => ['active', fn (ConcreteSubscription $s): bool => $s->active()],
            'pastDue' => ['pastDue', fn (ConcreteSubscription $s): bool => $s->pastDue()],
            'incomplete' => ['incomplete', fn (ConcreteSubscription $s): bool => $s->incomplete()],
            'canceled' => ['canceled', fn (ConcreteSubscription $s): bool => $s->canceled()],
            'notCanceled' => ['notCanceled', fn (ConcreteSubscription $s): bool => ! $s->canceled()],
            'onTrial' => ['onTrial', fn (ConcreteSubscription $s): bool => $s->onTrial()],
            'notOnTrial' => ['notOnTrial', fn (ConcreteSubscription $s): bool => ! $s->onTrial()],
            'onGracePeriod' => ['onGracePeriod', fn (ConcreteSubscription $s): bool => $s->onGracePeriod()],
            'notOnGracePeriod' => ['notOnGracePeriod', fn (ConcreteSubscription $s): bool => ! $s->onGracePeriod()],
            'ended' => ['ended', fn (ConcreteSubscription $s): bool => $s->hasEnded()],
            'notEnded' => ['notEnded', fn (ConcreteSubscription $s): bool => ! $s->hasEnded()],
            'paused' => ['paused', fn (ConcreteSubscription $s): bool => $s->paused()],
            'notPaused' => ['notPaused', fn (ConcreteSubscription $s): bool => ! $s->paused()],
            'onPausedGracePeriod' => ['onPausedGracePeriod', fn (ConcreteSubscription $s): bool => $s->onPausedGracePeriod()],
            'notOnPausedGracePeriod' => ['notOnPausedGracePeriod', fn (ConcreteSubscription $s): bool => ! $s->onPausedGracePeriod()],
        ];
    }

    /**
     * The whole state space: 8 statuses × 3 ends_at × 3 trial_ends_at × 3 paused_at.
     *
     * Some of these states are incoherent — a driver should never write `unpaid` with a future
     * trial date — and they are seeded anyway. A scope and a predicate disagreeing on a row
     * nobody meant to create is still a scope and a predicate disagreeing, and the query is
     * what a dunning cron runs against whatever the table actually holds.
     *
     * @return list<ConcreteSubscription>
     */
    private function seedEveryState(): array
    {
        $user = User::query()->create(['name' => 'Ada']);
        $seeded = 0;
        $rows = [];

        foreach (SubscriptionStatus::cases() as $status) {
            foreach ([null, 'past', 'future'] as $endsAt) {
                foreach ([null, 'past', 'future'] as $trialEndsAt) {
                    foreach ([null, 'past', 'future'] as $pausedAt) {
                        $subscription = ConcreteSubscription::query()->create([
                            'owner_type' => $user->getMorphClass(),
                            'owner_id' => $user->getKey(),
                            'type' => 'default',
                            'provider' => 'fake',
                            // (provider, provider_id) is uniquely indexed — the idempotency guard.
                            'provider_id' => 'sub_'.(++$seeded),
                            'status' => $status,
                            'ends_at' => self::date($endsAt),
                            'trial_ends_at' => self::date($trialEndsAt),
                            'paused_at' => self::date($pausedAt),
                        ]);

                        $this->assertInstanceOf(ConcreteSubscription::class, $subscription);

                        $rows[] = $subscription;
                    }
                }
            }
        }

        $this->assertCount(216, $rows, 'The matrix must cover the whole space, or it is a sample.');

        return $rows;
    }

    private static function date(?string $when): ?Carbon
    {
        return match ($when) {
            'future' => now()->addDays(3),
            'past' => now()->subDay(),
            default => null,
        };
    }

    /**
     * Describes a row well enough to debug a failure without re-deriving it from an id.
     */
    private static function describe(ConcreteSubscription $subscription): string
    {
        return sprintf(
            '%s / ends_at %s / trial_ends_at %s / paused_at %s',
            $subscription->status->value,
            self::describeDate($subscription->ends_at),
            self::describeDate($subscription->trial_ends_at),
            self::describeDate($subscription->paused_at),
        );
    }

    private static function describeDate(?CarbonImmutable $date): string
    {
        return match (true) {
            $date === null => 'null',
            $date->isFuture() => 'future',
            default => 'past',
        };
    }

    // ---------------------------------------------------------------------
    // The acceptance criterion
    // ---------------------------------------------------------------------

    /**
     * AC-1. For every scope: the rows the QUERY returns are exactly the rows the PREDICATE
     * answers true for, over all 72 states.
     *
     * @param  callable(ConcreteSubscription): bool  $predicate
     */
    #[DataProvider('scopes')]
    public function test_every_scope_agrees_with_its_predicate(string $scope, callable $predicate): void
    {
        $this->assertScopeAgreesWithPredicate($scope, $predicate);
    }

    /**
     * AC-2. The same, with both dunning toggles flipped.
     *
     * valid() and active() read Cashier::deactivatesPastDue()/deactivatesIncomplete(), and so
     * must their scopes — Paddle's scopeValid reads the same toggle its valid() does (:183).
     * A scope that ignored it would disagree with its predicate on precisely the fixture set
     * this issue's acceptance compares, and only there: every other row would still agree, so
     * the default-configuration run above cannot catch it.
     *
     * @param  callable(ConcreteSubscription): bool  $predicate
     */
    #[DataProvider('scopes')]
    public function test_every_scope_agrees_with_its_predicate_under_a_lenient_dunning_policy(
        string $scope,
        callable $predicate,
    ): void {
        Cashier::keepPastDueSubscriptionsActive();
        Cashier::keepIncompleteSubscriptionsActive();

        $this->assertScopeAgreesWithPredicate($scope, $predicate);
    }

    /**
     * @param  callable(ConcreteSubscription): bool  $predicate
     */
    private function assertScopeAgreesWithPredicate(string $scope, callable $predicate): void
    {
        $rows = $this->seedEveryState();

        $expected = array_values(array_map(
            fn (ConcreteSubscription $s): string => (string) $s->getKey(),
            array_filter($rows, $predicate),
        ));

        /** @var list<string> $actual */
        $actual = ConcreteSubscription::query()->{$scope}()->pluck('id')->all();

        sort($expected);
        sort($actual);

        // Report the disagreeing STATES, not a diff of uuids nobody can read.
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row->getKey()] = self::describe($row);
        }

        $missing = array_map(fn (string $id): string => $byId[$id], array_diff($expected, $actual));
        $extra = array_map(fn (string $id): string => $byId[$id], array_diff($actual, $expected));

        $this->assertSame($expected, $actual, sprintf(
            "%s() and scope%s() disagree.\n  predicate says yes, query missed: %s\n  query returned, predicate says no: %s",
            $scope,
            ucfirst($scope),
            $missing === [] ? '—' : implode('; ', $missing),
            $extra === [] ? '—' : implode('; ', $extra),
        ));

        // A scope that matched everything, or nothing, would agree with a predicate that did
        // the same and prove nothing about either. Every scope here divides the 216 rows.
        $this->assertNotCount(0, $actual, sprintf('scope%s() matched no row in the whole space.', ucfirst($scope)));
        $this->assertNotCount(216, $actual, sprintf('scope%s() matched every row in the whole space.', ucfirst($scope)));
    }

    // ---------------------------------------------------------------------
    // The one group Eloquent does not supply
    // ---------------------------------------------------------------------

    /**
     * AC-3. scopeValid()'s internal OR must stay parenthesised against the two guards above it.
     *
     * A scope does not need a group to defend itself from what it is CHAINED to — Eloquent
     * already isolates a scope's own constraints: Builder::callScope() counts the wheres before
     * and after the body and hands the new ones to addNewWheresWithinGroup(). Verified: Stripe's
     * ungrouped scopeNotOnTrial body (:396), invoked as a scope after another condition, still
     * compiles to `status = ? and (trial_ends_at is null or trial_ends_at <= ?)`. So a test that
     * chained two of our scopes and checked for a leak would be testing the framework, and could
     * not fail.
     *
     * What callScope does NOT do is parenthesise AND against OR inside one body, and scopeValid
     * is the only scope here that has both. Flattened, it would rebind to
     * `notEnded AND status NOT IN (denied) OR onTrial OR onGracePeriod` — and the two rows below
     * are the ones that proves it on: a status #22 denies unconditionally, carrying a date that
     * would otherwise talk over the denial. They are in the matrix too, but only as 2 rows out
     * of 72; here they are the whole assertion, and named.
     */
    #[DataProvider('unrecoverableStatuses')]
    public function test_valids_guards_are_not_rebound_by_its_own_or(SubscriptionStatus $status): void
    {
        $this->userWithSubscription($status, 'sub_denied_on_trial', ['trial_ends_at' => now()->addDays(3)]);
        $this->userWithSubscription($status, 'sub_denied_in_grace', ['ends_at' => now()->addDays(3)]);

        $rows = ConcreteSubscription::query()->valid()->get();

        $this->assertCount(0, $rows, sprintf(
            'scopeValid() let a %s row through on the strength of a date. The deniesAccess() guard '
            .'rebound to the OR arms instead of gating them.',
            $status->value,
        ));

        // ...and the predicate agrees, which is the whole point of the pairing.
        $this->assertFalse(ConcreteSubscription::query()->first()?->valid());
    }

    /**
     * @return array<string, array{SubscriptionStatus}>
     */
    public static function unrecoverableStatuses(): array
    {
        return [
            'dunning exhausted' => [SubscriptionStatus::Unpaid],
            'initial payment never completed' => [SubscriptionStatus::IncompleteExpired],
        ];
    }

    // ---------------------------------------------------------------------
    // What the issue actually asked for
    // ---------------------------------------------------------------------

    /**
     * AC-4. The issue's opening line: "User::whereHas('subscriptions', fn ($q) => $q->active())
     * is impossible."
     *
     * It is the reason the scopes exist. A predicate cannot reach inside a relationship-existence
     * subquery at all — no amount of PHP on a hydrated model helps, because the point is not to
     * hydrate.
     */
    public function test_a_billable_can_be_filtered_by_its_subscriptions_state(): void
    {
        $active = $this->userWithSubscription(SubscriptionStatus::Active, 'sub_active');
        $pastDue = $this->userWithSubscription(SubscriptionStatus::PastDue, 'sub_past_due');
        $graced = $this->userWithSubscription(SubscriptionStatus::Canceled, 'sub_graced', ['ends_at' => now()->addDays(3)]);

        $activeIds = User::query()
            ->whereHas('subscriptions', fn (Builder $query) => $query->active())
            ->pluck('id')->all();

        $this->assertSame([$active->getKey()], $activeIds, 'Only the active subscription counts as active.');

        // valid() is the broader access question, so the grace-period customer joins.
        $validIds = User::query()
            ->whereHas('subscriptions', fn (Builder $query) => $query->valid())
            ->pluck('id')->all();

        // Canonicalizing, not assertSame: the query has no ORDER BY, so the order is the
        // engine's to choose. SQLite hands back insertion order today and would make an
        // ordered assertion pass, right up until a host app runs this on MySQL — a green
        // suite broken by a database rather than by a change.
        $this->assertEqualsCanonicalizing([$active->getKey(), $graced->getKey()], $validIds);
        $this->assertNotContains($pastDue->getKey(), $validIds, 'A failed renewal is not access.');
    }

    /**
     * The dunning cron the issue describes: "all past_due subscriptions ... means loading every
     * subscription into memory". It does not any more — and that is asserted as a row count out
     * of the database, not inferred from the result being correct.
     *
     * The filtering has to be shown to happen in SQL, because a PHP filter over all 72 rows
     * would return exactly the same 9 models and satisfy every other assertion here. Counting
     * hydrated rows is what tells the two apart, and it is the whole claim the issue makes.
     */
    public function test_the_dunning_query_filters_in_sql_rather_than_hydrating_the_table(): void
    {
        $this->seedEveryState();

        // count() is the assertion that cannot be satisfied by a PHP filter: it is a COUNT(*)
        // the database answers, so it sees whatever the WHERE clause left. A scope that filtered
        // after hydration would answer 216 here and still return the right 27 from get().
        $this->assertSame(27, ConcreteSubscription::query()->pastDue()->count(), 'The scope must narrow the query, not the collection.');

        $pastDue = ConcreteSubscription::query()->pastDue()->get();

        $this->assertCount(27, $pastDue, 'Twenty-seven of the 216 rows are past_due — one per date combination.');
        $this->assertTrue($pastDue->every(fn (ConcreteSubscription $s): bool => $s->pastDue()));
    }

    /**
     * The two entry points CLAUDE.md documents for reaching a scope off the model, run as
     * written.
     *
     * #38 is open because that file once described an API that did not exist, and this change
     * nearly added another: `Subscription::query()->pastDue()` reads perfectly well and is a
     * fatal, because Models\Subscription is abstract. A snippet in a doc is a claim; this is the
     * test that makes it one the build can check.
     */
    public function test_the_documented_entry_points_reach_the_scopes(): void
    {
        $user = $this->userWithSubscription(SubscriptionStatus::PastDue, 'sub_1');

        // `$user->subscriptions()->notOnTrial()->notCanceled()->get()` — a relation forwards
        // scope calls to the model's builder.
        $this->assertCount(1, $user->subscriptions()->notOnTrial()->notCanceled()->get());

        // `Cashier::subscriptionModel('fake')::query()->pastDue()->get()` — the registry hands
        // back the driver's concrete class, which is the thing that can be queried.
        $subscriptions = Cashier::subscriptionModel('fake');

        $this->assertSame(ConcreteSubscription::class, $subscriptions);
        $this->assertCount(1, $subscriptions::query()->pastDue()->get());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function userWithSubscription(SubscriptionStatus $status, string $providerId, array $overrides = []): User
    {
        $user = User::query()->create(['name' => 'Ada']);

        ConcreteSubscription::query()->create(array_merge([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => $providerId,
            'status' => $status,
        ], $overrides));

        return $user;
    }
}
