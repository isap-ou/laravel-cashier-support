<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;

/**
 * #25: active() computed what Cashier calls valid(), so an app copying the Cashier idiom
 * got the opposite answer in the one state where it decides whether a non-paying customer
 * keeps their seat — and no toggle existed to say which answer it wanted.
 *
 * The rename is the smaller half. What this class mostly pins is that the rename did NOT
 * move access: both references route subscribed() through valid() and never through active()
 * (Stripe Concerns/ManagesSubscriptions.php:142, :196, :220; Paddle :137, :155, :179), and so
 * do we — before and after. A rename that quietly narrowed subscribed() would look exactly
 * like a rename that did not, which is why the equivalence is asserted row by row rather
 * than trusted.
 */
class SubscriptionAccessTest extends TestCase
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

    /**
     * Distinct per subscription: (provider, provider_id) is uniquely indexed — that is the
     * idempotency guard doing its job, and a test that seeds two states must not trip it.
     */
    private int $seeded = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscription(SubscriptionStatus $status, array $overrides = []): ConcreteSubscription
    {
        $user = User::query()->create(['name' => 'Ada']);

        $subscription = ConcreteSubscription::query()->create(array_merge([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_'.(++$this->seeded),
            'status' => $status,
        ], $overrides));

        $this->assertInstanceOf(ConcreteSubscription::class, $subscription);

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function userWith(SubscriptionStatus $status, array $overrides = []): User
    {
        $owner = $this->subscription($status, $overrides)->owner;

        $this->assertInstanceOf(User::class, $owner);

        return $owner;
    }

    // ---------------------------------------------------------------------
    // The acceptance criterion
    // ---------------------------------------------------------------------

    public function test_a_past_due_subscription_in_grace_is_valid_but_not_active(): void
    {
        // The ticket's case, and the whole reason for the split. The customer paid through
        // ends_at, so access survives (valid) — but the renewal failed, so nothing about
        // this subscription is currently active. Stripe answers exactly this way:
        // active() excludes past_due (Subscription.php:229-236) while valid() is carried by
        // onGracePeriod() (:177-180).
        $subscription = $this->subscription(SubscriptionStatus::PastDue, [
            'ends_at' => now()->addDays(3),
        ]);

        $this->assertTrue($subscription->onGracePeriod());
        $this->assertFalse($subscription->active(), 'A failed renewal is not an active subscription.');
        $this->assertTrue($subscription->valid(), 'The customer paid through ends_at, so access survives.');
    }

    public function test_keeping_past_due_subscriptions_active_restores_the_old_answer(): void
    {
        // "Flipping the toggle restores the current behaviour" — the ticket's second
        // acceptance line. Stripe: Cashier::keepPastDueSubscriptionsActive() (Cashier.php:189),
        // clearing $deactivatePastDue (:58). Paddle ships the same name (Cashier.php:250, :37).
        $subscription = $this->subscription(SubscriptionStatus::PastDue, [
            'ends_at' => now()->addDays(3),
        ]);

        Cashier::keepPastDueSubscriptionsActive();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->valid());
    }

    public function test_keeping_incomplete_subscriptions_active_grants_access_that_never_existed(): void
    {
        // NOT named "restores", unlike its past_due twin above, because it does not: the old
        // active() answered false for `incomplete` in every configuration — isActive() is
        // Active|Trialing and nothing could change that. This toggle is new leniency, not a
        // compatibility shim, and the difference is what a reader is entitled to know.
        //
        // Stripe-only (Cashier.php:65, :201) — Paddle has no incomplete status at all.
        $subscription = $this->subscription(SubscriptionStatus::Incomplete);

        $this->assertFalse($this->preRenameActive($subscription), 'There was no old answer to restore.');
        $this->assertFalse($subscription->active());

        Cashier::keepIncompleteSubscriptionsActive();

        $this->assertTrue($subscription->active());
    }

    // ---------------------------------------------------------------------
    // The toggle is not sugar
    // ---------------------------------------------------------------------

    public function test_the_past_due_toggle_reaches_subscribed(): void
    {
        // The test that justifies the toggle existing at all. An app can always write
        // `$sub->valid() || $sub->pastDue()` at its own call site — but it cannot reach
        // INSIDE Concerns\ManagesSubscriptions::subscribed(), which is ours. Without the
        // toggle, leniency would mean abandoning subscribed() entirely.
        //
        // ends_at is null on purpose: this is the ordinary past_due state, the one where
        // no grace period is carrying access, so the toggle is the only thing that can
        // change the answer.
        $user = $this->userWith(SubscriptionStatus::PastDue);

        $this->assertFalse($user->subscribed('default'));

        Cashier::keepPastDueSubscriptionsActive();

        $this->assertTrue($user->subscribed('default'));
    }

    #[Depends('test_the_past_due_toggle_reaches_subscribed')]
    public function test_a_toggle_does_not_leak_into_the_next_test(): void
    {
        // Why the flags are instance state on the singleton manager rather than the
        // references' public statics: nothing here resets them, and nothing has to. Testbench
        // rebuilds the container per test, so the manager — and the flags with it — cannot
        // outlive the test that set them. A public static would survive the whole process and
        // land its failure on whichever test ran next.
        //
        // #[Depends] is the point of the test, not decoration. Asserting a default is false
        // proves nothing unless something has already tried to make it true, and relying on
        // declaration order to arrange that would evaporate silently under --order-by=random
        // or a reshuffle. This names the arrangement instead.
        $this->assertTrue(Cashier::deactivatesPastDue(), 'The previous test flipped this; it must not still be flipped.');
        $this->assertFalse($this->subscription(SubscriptionStatus::PastDue)->active());
    }

    // ---------------------------------------------------------------------
    // The rename moved nothing
    // ---------------------------------------------------------------------

    public function test_the_rename_did_not_narrow_subscribed(): void
    {
        // The load-bearing assertion of the whole change. Concerns\ManagesSubscriptions::subscribed()
        // was moved from active() to valid(); left on active(), every app in the world would
        // quietly stop serving customers inside their paid-through grace period.
        //
        // Canceled + future ends_at is the ONLY ordinary state where the two predicates part
        // company, so it is the only one that can tell the two versions apart. Every test that
        // goes through the model directly is blind to this.
        $user = $this->userWith(SubscriptionStatus::Canceled, ['ends_at' => now()->addDays(3)]);

        $user->subscription('default')?->items()->create([
            'provider' => 'fake',
            'price' => 'price_monthly',
            'quantity' => 1,
        ]);

        $this->assertFalse($user->subscription('default')?->active(), 'A canceled subscription is not active.');
        $this->assertTrue($user->subscribed('default'), 'It is still valid — the customer paid through ends_at.');
        $this->assertTrue($user->subscribedToPrice('price_monthly'), 'subscribedToPrice() gates on the same predicate.');
    }

    /**
     * The whole access surface, over BOTH dates the predicates read.
     *
     * `$wasValid` is the pre-rename `active()` body, and it is not hand-computed prose — it
     * is reimplemented at preRenameActive() below and ASSERTED, so the comparison is real.
     * An earlier version of this matrix varied only `ends_at` and hand-wrote the column; it
     * was therefore structurally blind to the trial_ends_at rows, which are the only ones
     * where the rename moved access at all, and it let a false claim reach the CHANGELOG.
     *
     * Where `$wasValid` and `$isValidNow` differ, the row is a DELIBERATE behaviour change
     * and says so in its name. There are exactly three, all the same shape: a future
     * trial_ends_at under a status that is not Trialing. Our onTrial() is date-based
     * (Models/Subscription.php:167-174) and Stripe's valid() composes it in
     * (Subscription.php:177-180); the old body consulted only isActive() || onGracePeriod().
     */
    #[DataProvider('accessMatrix')]
    public function test_every_state_answers_both_predicates(
        SubscriptionStatus $status,
        ?string $endsAt,
        ?string $trialEndsAt,
        bool $wasValid,
        bool $isValidNow,
        bool $isActiveNow,
    ): void {
        $subscription = $this->subscription($status, [
            'ends_at' => self::date($endsAt),
            'trial_ends_at' => self::date($trialEndsAt),
        ]);

        $this->assertSame($wasValid, $this->preRenameActive($subscription), 'The pre-rename column must be the pre-rename body, not my arithmetic.');
        $this->assertSame($isValidNow, $subscription->valid(), 'valid() is the access question.');
        $this->assertSame($isActiveNow, $subscription->active(), 'active() is the narrow one.');
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
     * `Models\Subscription::active()` exactly as it stood at 4bc0646, before this change.
     *
     * Duplicated on purpose. The claim "the rename did not move access" is only worth
     * anything if the old answer is computed rather than remembered, and this is the only
     * way to compute it once the original is gone.
     */
    private function preRenameActive(ConcreteSubscription $subscription): bool
    {
        if ($subscription->hasEnded() || $subscription->status->deniesAccess()) {
            return false;
        }

        return $subscription->status->isActive() || $subscription->onGracePeriod();
    }

    /**
     * @return array<string, array{SubscriptionStatus, ?string, ?string, bool, bool, bool}>
     */
    public static function accessMatrix(): array
    {
        return [
            //                                       ends_at   trial     was valid  now valid  now active
            'active' => [SubscriptionStatus::Active, null, null, true, true, true],
            'trialing' => [SubscriptionStatus::Trialing, null, null, true, true, true],
            'canceled, still paid through' => [SubscriptionStatus::Canceled, 'future', null, true, true, false],
            'canceled and over' => [SubscriptionStatus::Canceled, 'past', null, false, false, false],
            // A live status behind a past ends_at — what a lagging webhook leaves behind.
            // The rows that pin the hasEnded() guard: without it the status, or the trial
            // date below it, would carry access straight past the end of it.
            'trialing but over' => [SubscriptionStatus::Trialing, 'past', null, false, false, false],
            'over, but the trial date lingers' => [SubscriptionStatus::Trialing, 'past', 'future', false, false, false],
            'past due' => [SubscriptionStatus::PastDue, null, null, false, false, false],
            'past due, still paid through' => [SubscriptionStatus::PastDue, 'future', null, true, true, false],
            'paused' => [SubscriptionStatus::Paused, null, null, false, false, false],
            'unpaid, despite a paid-through date' => [SubscriptionStatus::Unpaid, 'future', null, false, false, false],
            'incomplete' => [SubscriptionStatus::Incomplete, null, null, false, false, false],
            'initial payment expired' => [SubscriptionStatus::IncompleteExpired, 'future', null, false, false, false],

            // The three deliberate changes. A future trial_ends_at now carries access under
            // a status that never granted it before — Stripe's shape, an incoherent state a
            // driver should not write, and a widening rather than a narrowing. Named here so
            // the CHANGELOG has to describe what the tests actually assert.
            'CHANGED — paused, with a live trial date' => [SubscriptionStatus::Paused, null, 'future', false, true, false],
            'CHANGED — canceled, with a live trial date' => [SubscriptionStatus::Canceled, null, 'future', false, true, false],
            'CHANGED — past due, with a live trial date' => [SubscriptionStatus::PastDue, null, 'future', false, true, false],

            // ...but deniesAccess() still outranks the trial date, so the widening stops at
            // the two statuses #22 closed. This is the row that proves the change above is
            // bounded rather than general.
            'unpaid, with a live trial date' => [SubscriptionStatus::Unpaid, null, 'future', false, false, false],
            'expired, with a live trial date' => [SubscriptionStatus::IncompleteExpired, null, 'future', false, false, false],
        ];
    }

    // ---------------------------------------------------------------------
    // #22 outranks the toggles
    // ---------------------------------------------------------------------

    /**
     * The unconditional half stays unconditional. Stripe excludes exactly these two with no
     * opt-out (Subscription.php:232-235 — the past_due and incomplete arms are gated, these
     * are not), and #22 gave them those semantics here. A toggle must not reopen them: the
     * money never arrived, so there is no policy to apply.
     */
    #[DataProvider('unrecoverableStatuses')]
    public function test_a_denied_status_stays_denied_with_both_toggles_flipped(SubscriptionStatus $status): void
    {
        $subscription = $this->subscription($status, ['ends_at' => now()->addDays(3)]);

        Cashier::keepPastDueSubscriptionsActive();
        Cashier::keepIncompleteSubscriptionsActive();

        $this->assertTrue($subscription->onGracePeriod(), 'The paid-through date is there to be ignored.');
        $this->assertFalse($subscription->active());
        $this->assertFalse($subscription->valid());
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
    // Where our enum outgrows Stripe's body
    // ---------------------------------------------------------------------

    public function test_a_paused_subscription_is_not_active(): void
    {
        // Stripe's active() is status-NEGATIVE — "not ended, and not one of these four bad
        // statuses" (Subscription.php:229-236). It can afford that because Stripe has no
        // paused status. Ours does, so copying that body verbatim would report a paused
        // subscription active on the strength of not being listed. Paddle, which HAS the
        // status, says false (paused() :314, active() :251).
        $subscription = $this->subscription(SubscriptionStatus::Paused);

        $this->assertFalse($subscription->active());
        $this->assertFalse($subscription->valid());
    }

    public function test_a_future_trial_end_carries_access_whatever_the_status_says(): void
    {
        // The one row where the rename is a deliberate behaviour CHANGE rather than a
        // rename. Our onTrial() is date-based (Models/Subscription.php:167-174) and Stripe's
        // valid() includes it (:177-180), so a future trial_ends_at carries access even
        // under a status that is not Trialing. The old active() consulted only
        // isActive() || onGracePeriod() and answered false here.
        //
        // The state is incoherent — a driver should not write it — and it is pinned rather
        // than left to be discovered, because "aligns with Stripe" is a claim, not an alibi.
        $subscription = $this->subscription(SubscriptionStatus::Paused, [
            'trial_ends_at' => now()->addDays(7),
        ]);

        $this->assertTrue($subscription->onTrial());
        $this->assertTrue($subscription->valid());
        $this->assertFalse($subscription->active(), 'The status still decides active().');
    }

    // ---------------------------------------------------------------------
    // Pause (#30) — access is unchanged; the paused fact lives in paused_at
    // ---------------------------------------------------------------------

    public function test_a_pause_scheduled_for_period_end_stays_valid_until_paused_at(): void
    {
        // AC-1, the issue's stated criterion. A pause scheduled for cycle end keeps the
        // subscription usable until paused_at: the driver holds the status at Active while
        // paused_at is in the future, exactly as Paddle does (valid()/active() never read
        // paused_at; only recurring() subtracts the grace, Subscription.php:272). So access is
        // untouched — the pause is merely SCHEDULED — and onPausedGracePeriod() is what reports it.
        $subscription = $this->subscription(SubscriptionStatus::Active, [
            'paused_at' => now()->addDays(5),
        ]);

        $this->assertTrue($subscription->valid());
        $this->assertTrue($subscription->active(), 'A scheduled pause has not taken effect, so access is unchanged.');
        $this->assertTrue($subscription->onPausedGracePeriod());
        $this->assertFalse($subscription->paused(), 'The pause is scheduled, not in force.');
    }

    public function test_a_pause_in_force_is_paused_and_off_the_grace_period(): void
    {
        // paused_at in the past: the pause has landed. paused() flips true and the grace period
        // is over. Whether access survives is the STATUS's business — see the two rows below.
        $subscription = $this->subscription(SubscriptionStatus::Paused, [
            'paused_at' => now()->subDay(),
        ]);

        $this->assertTrue($subscription->paused());
        $this->assertFalse($subscription->onPausedGracePeriod());
    }

    public function test_whether_a_paused_subscription_keeps_access_is_the_gateways_to_decide(): void
    {
        // The edge the spec pins deliberately: paused() reads paused_at, and access reads the
        // status, so the two answer independently — and the references genuinely differ.
        //
        // Paddle moves the status to Paused (Subscription.php:314), so a paused subscription is
        // not active. Stripe's pause_collection pauses billing and leaves the status Active, so a
        // "paused" Stripe subscription is STILL active — the docs say so outright. Each is faithful
        // to its gateway; the difference is surfaced, not hidden.
        $paddleStyle = $this->subscription(SubscriptionStatus::Paused, ['paused_at' => now()->subDay()]);
        $stripeStyle = $this->subscription(SubscriptionStatus::Active, ['paused_at' => now()->subDay()]);

        $this->assertTrue($paddleStyle->paused());
        $this->assertFalse($paddleStyle->active(), 'Paddle pauses via the status, so access stops.');

        $this->assertTrue($stripeStyle->paused());
        $this->assertTrue($stripeStyle->active(), 'Stripe pauses collection only, so access continues.');
    }

    public function test_a_subscription_that_was_never_paused_reports_neither_pause_state(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $this->assertFalse($subscription->paused());
        $this->assertFalse($subscription->onPausedGracePeriod());
    }

    // ---------------------------------------------------------------------
    // The status predicates
    // ---------------------------------------------------------------------

    public function test_the_dunning_statuses_answer_for_themselves(): void
    {
        // Stripe pastDue() :208 / incomplete() :187; Paddle pastDue() :293. Thin against our
        // enum — $sub->status === SubscriptionStatus::PastDue says the same — but #56
        // (hasIncompletePayment) composes exactly these two, and #29 pairs each with a scope.
        $this->assertTrue($this->subscription(SubscriptionStatus::PastDue)->pastDue());
        $this->assertFalse($this->subscription(SubscriptionStatus::Active)->pastDue());
        $this->assertTrue($this->subscription(SubscriptionStatus::Incomplete)->incomplete());
        $this->assertFalse($this->subscription(SubscriptionStatus::IncompleteExpired)->incomplete());
    }

    public function test_a_toggle_does_not_touch_what_the_status_reports(): void
    {
        // pastDue() reports the STATUS; active() applies the POLICY. Folding the toggle into
        // the report would make a subscription stop being past due because an app chose to
        // keep serving it — and #56's hasIncompletePayment() would then answer that a
        // customer with a failed payment has no failed payment.
        $subscription = $this->subscription(SubscriptionStatus::PastDue);

        Cashier::keepPastDueSubscriptionsActive();

        $this->assertTrue($subscription->pastDue(), 'The bill is still unpaid; only access changed.');
        $this->assertTrue($subscription->active());
    }

    // ---------------------------------------------------------------------
    // The price predicates
    // ---------------------------------------------------------------------

    public function test_a_subscription_knows_how_many_prices_it_carries(): void
    {
        // Stripe asks whether its own stripe_price column is null (:114) — a column we do not
        // have. Paddle counts items (:126), which is the only form expressible here and the
        // one #37 already shipped in Concerns\ManagesSubscriptions::cashierQuantityItem().
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_seats', 'quantity' => 3]);

        $this->assertTrue($subscription->hasSinglePrice());
        $this->assertFalse($subscription->hasMultiplePrices());

        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_support', 'quantity' => 1]);

        $this->assertFalse($subscription->hasSinglePrice());
        $this->assertTrue($subscription->hasMultiplePrices());
    }

    public function test_a_subscription_with_no_items_is_not_multi_price(): void
    {
        // Zero items is not "many". Worth its own row because `count() > 1` and `count() >= 1`
        // agree on every populated subscription and part company only here.
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $this->assertFalse($subscription->hasMultiplePrices());
        $this->assertTrue($subscription->hasSinglePrice());
    }

    public function test_a_subscription_knows_which_prices_it_carries(): void
    {
        // Stripe :148, Paddle :160. Both read the loaded items collection; ours queries,
        // matching subscribedToPrice() (Concerns/ManagesSubscriptions.php:124) rather than
        // hydrating every item to answer a boolean.
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_monthly', 'quantity' => 1]);

        $this->assertTrue($subscription->hasPrice('price_monthly'));
        $this->assertFalse($subscription->hasPrice('price_yearly'));
    }

    public function test_the_price_predicates_answer_the_same_when_the_items_are_eager_loaded(): void
    {
        // The predicates have two bodies — a loaded-collection read and a query — and every
        // other test in this class exercises only the query, because a freshly created model
        // has no loaded relation. The eager path was added to kill an N+1 and shipped
        // untested; two mutations survived on it before this test existed.
        //
        // Both paths must agree, always: which one runs is an accident of how the caller
        // fetched the row, and a predicate that answers differently for that reason is worse
        // than the N+1 it was avoiding.
        $subscription = $this->subscription(SubscriptionStatus::Active);
        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_seats', 'quantity' => 3]);

        $loaded = ConcreteSubscription::query()->with('items')->find($subscription->getKey());
        $this->assertInstanceOf(ConcreteSubscription::class, $loaded);
        $this->assertTrue($loaded->relationLoaded('items'), 'Without this the test would silently re-check the query path.');

        $this->assertTrue($loaded->hasPrice('price_seats'));
        $this->assertFalse($loaded->hasPrice('price_yearly'));
        $this->assertTrue($loaded->hasSinglePrice());
        $this->assertFalse($loaded->hasMultiplePrices());

        $loaded->items()->create(['provider' => 'fake', 'price' => 'price_support', 'quantity' => 1]);
        $loaded->load('items');

        $this->assertTrue($loaded->hasMultiplePrices());
        $this->assertFalse($loaded->hasSinglePrice());
        $this->assertTrue($loaded->hasPrice('price_support'));
    }

    public function test_the_eager_path_costs_nothing_which_is_the_only_reason_it_exists(): void
    {
        // The N+1 this path removes, asserted as a number rather than described. Both
        // references read $this->items and answer for free (Stripe :148, Paddle :160);
        // querying unconditionally turned a with('items') loop into one round-trip per row,
        // which is precisely what eager loading was asked to prevent.
        $subscription = $this->subscription(SubscriptionStatus::Active);
        $subscription->items()->create(['provider' => 'fake', 'price' => 'price_seats', 'quantity' => 3]);

        $loaded = ConcreteSubscription::query()->with('items')->find($subscription->getKey());
        $this->assertInstanceOf(ConcreteSubscription::class, $loaded);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $loaded->hasSinglePrice();
        $loaded->hasMultiplePrices();
        $loaded->hasPrice('price_seats');

        $this->assertSame([], DB::getQueryLog(), 'An eager-loaded subscription must answer its own predicates without touching the database.');
    }
}
