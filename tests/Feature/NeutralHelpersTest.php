<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Testing\FakePaymentMethodType;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\IntBackedPaymentMethodType;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * #37's small half: helpers both references have, that are entirely gateway-neutral, and that
 * we simply never wrote.
 *
 * None of them is a new capability or a new contract method. Each composes an operation this
 * package already owns, and inherits that operation's existing gate — which is the point worth
 * testing: the reason they were missing was never that a gateway could not do them.
 *
 * What IS worth pinning is where each one is narrower than the reference it is named after,
 * because a helper that silently answers a different question than the Cashier method of the
 * same name is worse than one that does not exist.
 */
class NeutralHelpersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::useModels('fake', [
            'subscription' => ConcreteSubscription::class,
            'subscription_item' => ConcreteSubscriptionItem::class,
        ]);
    }

    /**
     * @param  array<int, Capability>  $capabilities
     */
    private function driverSupporting(array $capabilities): FakeGateway
    {
        $gateway = new FakeGateway($capabilities);

        Cashier::extend('fake', fn () => $gateway);

        return $gateway;
    }

    public function test_a_trial_end_is_read_off_the_subscription(): void
    {
        $this->driverSupporting([Capability::Subscriptions]);
        $user = new User(['id' => 1]);

        ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'default',
            'provider' => 'fake',
            'provider_id' => 'sub_ext',
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->assertNotNull($user->trialEndsAt());
        $this->assertTrue($user->trialEndsAt()?->isFuture());
    }

    public function test_a_billable_with_no_subscription_has_no_trial_end(): void
    {
        // Not an error and not a generic trial: we have no generic-trial storage at all, so
        // there is no other place this could have looked. Both references would consult one
        // here (Stripe ManagesSubscriptions.php:118, Paddle :109) — see the method's docblock.
        $this->driverSupporting([Capability::Subscriptions]);

        $this->assertNull((new User(['id' => 1]))->trialEndsAt());
    }

    public function test_a_trial_end_is_read_per_type(): void
    {
        $this->driverSupporting([Capability::Subscriptions]);
        $user = new User(['id' => 1]);

        ConcreteSubscription::create([
            'owner_type' => User::class,
            'owner_id' => 1,
            'type' => 'swimming',
            'provider' => 'fake',
            'provider_id' => 'sub_swim',
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => now()->addDays(7),
        ]);

        $this->assertNotNull($user->trialEndsAt('swimming'));
        $this->assertNull($user->trialEndsAt(), 'The default type has no subscription, so it has no trial.');
    }

    public function test_a_default_payment_method_is_reported_when_the_gateway_has_one(): void
    {
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList]);
        $gateway->storedDefaultPaymentMethod = new PaymentMethod(id: 'pm_1', type: FakePaymentMethodType::Card);

        $this->assertTrue((new User)->hasDefaultPaymentMethod());
    }

    public function test_no_default_payment_method_is_reported_when_the_gateway_has_none(): void
    {
        $this->driverSupporting([Capability::PaymentMethodsList]);

        $this->assertFalse((new User)->hasDefaultPaymentMethod());
    }

    public function test_asking_for_a_default_refuses_on_a_gateway_that_cannot_list(): void
    {
        // Where this deliberately parts company with the reference, whose hasDefaultPaymentMethod()
        // reads a local column and simply answers false. Ours asks the gateway, so a gateway with
        // no payment-method concept refuses — catchably — rather than reporting a confident "no"
        // it never checked.
        $this->driverSupporting([]);

        $this->expectException(UnsupportedOperationException::class);

        (new User)->hasDefaultPaymentMethod();
    }

    public function test_a_payment_method_of_a_given_type_is_found(): void
    {
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList]);
        $gateway->storedPaymentMethods = [
            new PaymentMethod(id: 'pm_1', type: FakePaymentMethodType::Card),
        ];

        $user = new User;

        $this->assertTrue($user->hasPaymentMethod());
        $this->assertTrue($user->hasPaymentMethod(FakePaymentMethodType::Card));
        $this->assertFalse($user->hasPaymentMethod(FakePaymentMethodType::RevolutPay));
    }

    public function test_a_type_may_be_named_by_its_value_instead_of_its_enum(): void
    {
        // The driver owns the enum, so an app that does not want to name its driver's class
        // can pass the backing value instead — and must get the same answer.
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList]);
        $gateway->storedPaymentMethods = [
            new PaymentMethod(id: 'pm_1', type: FakePaymentMethodType::RevolutPay),
        ];

        $user = new User;

        $this->assertTrue($user->hasPaymentMethod('revolut_pay'));
        $this->assertFalse($user->hasPaymentMethod('card'));
    }

    public function test_a_type_matches_even_when_a_driver_ignored_the_string_backed_rule(): void
    {
        // Contracts\PaymentMethodType requires string backing, but BackedEnum permits int and
        // the language cannot narrow it — so a driver CAN ship this, and PHP will load it. The
        // failure it would cause is the silent kind: 1 === '1' is false, so hasPaymentMethod()
        // would report a confident "no" about a card that is right there.
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList]);
        $gateway->storedPaymentMethods = [
            new PaymentMethod(id: 'pm_1', type: IntBackedPaymentMethodType::Card),
        ];

        $user = new User;

        $this->assertTrue($user->hasPaymentMethod(IntBackedPaymentMethodType::Card));
        $this->assertTrue($user->hasPaymentMethod('1'), 'The enum\'s own value, as a string, must still match.');
    }

    public function test_a_bulk_delete_reaches_every_stored_method(): void
    {
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList, Capability::PaymentMethodsDelete]);
        $gateway->storedPaymentMethods = [
            new PaymentMethod(id: 'pm_1', type: FakePaymentMethodType::Card),
            new PaymentMethod(id: 'pm_2', type: FakePaymentMethodType::RevolutPay),
        ];

        (new User)->deletePaymentMethods();

        $this->assertSame(['pm_1', 'pm_2'], $gateway->deletedPaymentMethods);
    }

    public function test_a_bulk_delete_can_be_narrowed_to_one_type(): void
    {
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList, Capability::PaymentMethodsDelete]);
        $gateway->storedPaymentMethods = [
            new PaymentMethod(id: 'pm_1', type: FakePaymentMethodType::Card),
            new PaymentMethod(id: 'pm_2', type: FakePaymentMethodType::RevolutPay),
        ];

        (new User)->deletePaymentMethods(FakePaymentMethodType::RevolutPay);

        $this->assertSame(['pm_2'], $gateway->deletedPaymentMethods, 'The card must survive.');
    }

    public function test_a_bulk_delete_refuses_on_a_gateway_that_cannot_list(): void
    {
        // It lists before it deletes, so listing is the gate it hits first — and the refusal
        // must name that one, not the delete the caller was thinking about.
        $this->driverSupporting([Capability::PaymentMethodsDelete]);

        try {
            (new User)->deletePaymentMethods();
            $this->fail('Expected the bulk delete to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PaymentMethodsList, $e->capability);
        }
    }

    public function test_a_bulk_delete_short_of_both_gates_names_the_one_it_needs_first(): void
    {
        // The only case that can tell the two orderings apart, which is why it is here.
        // Everywhere else they agree: paymentMethods() gates listing itself, so checking
        // delete first still ends up throwing about listing. Only when a gateway has NEITHER
        // does the order become visible — and then the honest answer is the gate the
        // operation reaches first, because that is where it actually stopped.
        $this->driverSupporting([]);

        try {
            (new User)->deletePaymentMethods();
            $this->fail('Expected the bulk delete to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PaymentMethodsList, $e->capability);
        }
    }

    public function test_a_bulk_delete_refuses_on_a_gateway_that_cannot_delete(): void
    {
        $gateway = $this->driverSupporting([Capability::PaymentMethodsList]);
        $gateway->storedPaymentMethods = [
            new PaymentMethod(id: 'pm_1', type: FakePaymentMethodType::Card),
        ];

        try {
            (new User)->deletePaymentMethods();
            $this->fail('Expected the bulk delete to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PaymentMethodsDelete, $e->capability);
        }

        $this->assertSame([], $gateway->deletedPaymentMethods, 'Nothing may be deleted before the gate is passed.');
    }
}
