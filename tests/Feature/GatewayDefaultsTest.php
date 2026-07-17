<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\BaseGateway;
use Isapp\CashierSupport\Tests\Fixtures\MinimalGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * #28: a new contract method must stop being a fatal error in every driver.
 *
 * The rule underneath is one sentence: **every method on GatewayProvider has a default in
 * BaseGateway**, or the next method added to a contract reintroduces the BC break this
 * change exists to remove. That rule is not asserted here, and deliberately so — it is
 * enforced by Fixtures\MinimalGateway merely existing, since PHP will not load a concrete
 * subclass with a method left over. See its docblock; a sweep asserting the same thing was
 * written and deleted as unreachable.
 *
 * What is left here is everything the type system cannot say: that a refusal is catchable
 * rather than absent, that it names the intent the caller asked for, and that support is
 * read off the code instead of a hand-kept list.
 *
 * The other half is that defaults must not have cost anything: a driver that refuses an
 * operation still answers it, catchably, so gateways remain drop-in replacements for each
 * other. That is why segregating into opt-in interfaces was rejected, and
 * test_an_unsupported_operation_refuses_catchably_rather_than_not_existing is what would
 * notice if someone tried it again.
 */
class GatewayDefaultsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'minimal');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = new MinimalGateway;
        // Captured by value: Manager::extend() rebinds a non-static closure to itself.
        Cashier::extend('minimal', fn (): MinimalGateway => $gateway);
    }

    public function test_the_derivation_is_not_a_drivers_to_override(): void
    {
        // Worth a test precisely because the language is silent here. A driver that TRIES to
        // override supports() is refused by PHP at class load — loudly, no test needed. But
        // deleting the `final` keyword itself fatals nothing: it just quietly hands the gate
        // back to the driver, and a lie about capabilities reads exactly like the truth until
        // an app calls the method. Test where the compiler cannot speak; leave it alone where
        // it can (see Fixtures\MinimalGateway).
        foreach (['supports', 'capabilities'] as $method) {
            $this->assertTrue(
                (new ReflectionMethod(BaseGateway::class, $method))->isFinal(),
                "BaseGateway::{$method}() must stay final. It derives support from what a driver "
                .'actually overrode; letting a driver replace it makes the check its own again, which '
                .'is the drift this class removes. The extension point is declaredCapabilities().'
            );
        }
    }

    public function test_a_gateway_that_implements_nothing_still_loads_and_answers(): void
    {
        // Acceptance #2, and the reason this fixture exists: a driver that ignores every
        // optional operation is a valid driver.
        $provider = Cashier::provider('minimal');

        $this->assertInstanceOf(GatewayProvider::class, $provider);
        $this->assertSame([], $provider->capabilities());
    }

    public function test_an_unsupported_operation_refuses_catchably_rather_than_not_existing(): void
    {
        // This is the test that pins the design. Segregating the contract into opt-in
        // interfaces would make this a PHP Error ("Call to undefined method") instead of a
        // CashierException — and an app swapping one gateway for another would crash where
        // it used to catch. Drivers are drop-in replacements; that is the whole package.
        $user = new User(['id' => 1]);

        $this->expectException(UnsupportedOperationException::class);

        Cashier::provider('minimal')->pauseSubscription($user);
    }

    public function test_a_refusal_names_the_intent_not_merely_the_method(): void
    {
        // swapSubscription() is one method behind two capabilities. A gateway that can only
        // defer must refuse "now" BY THAT NAME, or the app is told the wrong reason.
        $user = new User(['id' => 1]);

        try {
            Cashier::provider('minimal')->swapSubscription($user, 'default', 'price_x', SwapTiming::AtPeriodEnd);
            $this->fail('Expected the default swap to refuse.');
        } catch (UnsupportedOperationException $e) {
            $this->assertStringContainsString(Capability::SubscriptionSwapAtPeriodEnd->value, $e->getMessage());
            $this->assertStringNotContainsString(Capability::SubscriptionSwapImmediate->value, $e->getMessage());
        }
    }

    public function test_support_is_read_off_the_code_not_off_a_declaration(): void
    {
        // FakeGateway implements every operation and declares no derivable capability. If
        // support were still a hand-kept list this would be false for all of them.
        $fake = new class extends BaseGateway
        {
            protected function declaredCapabilities(): array
            {
                return [];
            }

            public function pauseSubscription(Model $billable, string $type = 'default'): Subscription
            {
                return new Subscription(id: 'sub_x', type: $type, status: SubscriptionStatus::Paused);
            }
        };

        $this->assertTrue($fake->supports(Capability::SubscriptionPause), 'An overridden method must report its capability supported, undeclared.');
        $this->assertFalse($fake->supports(Capability::SubscriptionResume), 'A method left as the default must report its capability unsupported.');
    }

    public function test_a_capability_needing_several_methods_needs_all_of_them(): void
    {
        // "Any" would let a gateway that lists invoices but cannot render one claim Invoices,
        // and the app finds out at the download.
        $partial = new class extends BaseGateway
        {
            protected function declaredCapabilities(): array
            {
                return [];
            }

            public function invoices(Model $billable, array $parameters = []): array
            {
                return [];
            }
        };

        $this->assertFalse($partial->supports(Capability::Invoices), 'Invoices needs all three methods; one is not enough.');
    }

    public function test_every_capability_either_maps_to_methods_or_is_an_intent(): void
    {
        // Inclusion by default: a 21st case must classify itself. methods() is a match with
        // no default arm, so an unclassified case throws rather than quietly returning [] —
        // this asserts the classification is deliberate, not merely present.
        $intents = [
            Capability::SubscriptionSwapImmediate, Capability::SubscriptionSwapAtPeriodEnd,
            Capability::CheckoutPrices, Capability::CheckoutAmount,
            Capability::SubscriptionTrials, Capability::SubscriptionQuantity,
            Capability::SubscriptionMetadata, Capability::Taxes,
        ];

        foreach (Capability::cases() as $capability) {
            $methods = $capability->methods();

            if (in_array($capability, $intents, true)) {
                $this->assertSame([], $methods, "[{$capability->value}] is intent-granular and must map to no method.");

                continue;
            }

            $this->assertNotEmpty($methods, "[{$capability->value}] maps to no gateway method and is not on the intent list. Classify it.");

            foreach ($methods as $method) {
                $this->assertTrue(
                    method_exists(GatewayProvider::class, $method),
                    "[{$capability->value}] maps to [{$method}], which is not a method on GatewayProvider."
                );
            }
        }

        $this->assertCount(20, Capability::cases());
    }

    public function test_a_default_returns_nothing_it_only_refuses(): void
    {
        // A default that returned a value would be a smart stub — the thing
        // .claude/rules/smart-stubs.md forbids: the call succeeds, the data goes nowhere,
        // and the app never learns.
        foreach ((new ReflectionClass(BaseGateway::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), ['capabilities', 'supports'], true)) {
                continue;
            }

            $type = $method->getReturnType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type, "[{$method->getName()}] must keep the contract's return type.");
        }
    }
}
