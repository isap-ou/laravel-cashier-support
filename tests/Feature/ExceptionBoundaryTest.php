<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use InvalidArgumentException;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which failures an app is expected to catch.
 *
 * The line is the one Stripe and Paddle Cashier draw, and it is not arbitrary:
 *
 *  - A **billing** failure is a fact about the world — the card was declined, the
 *    gateway cannot pause, the customer does not exist. The app cannot prevent it,
 *    so it must be able to catch it: everything in the CashierException hierarchy.
 *  - A **malformed argument** is a programmer error — swapping to no price at all,
 *    checking out a negative amount. It is not caught, it is not committed; the
 *    reference throws SPL's InvalidArgumentException for exactly these
 *    (vendor/laravel/cashier/src/Subscription.php:718).
 *
 * A driver that raises a bare exception for a *billing* failure breaks
 * `catch (CashierException)` and is a defect. One that raises
 * InvalidArgumentException for a malformed argument is following the contract.
 */
class ExceptionBoundaryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    public function test_the_hierarchy_covers_every_failure_an_app_is_meant_to_catch(): void
    {
        $exceptions = glob(dirname(__DIR__, 2).'/src/Exceptions/*.php') ?: [];

        $this->assertNotEmpty($exceptions);

        foreach ($exceptions as $file) {
            $class = 'Isapp\\CashierSupport\\Exceptions\\'.basename($file, '.php');

            $this->assertTrue(
                $class === CashierException::class || is_subclass_of($class, CashierException::class),
                "[{$class}] must extend CashierException so an app can catch the whole hierarchy.",
            );
        }
    }

    public function test_a_malformed_argument_is_not_a_cashier_exception(): void
    {
        // It is a programmer error, and dressing it up as a billing failure would
        // invite an app to catch — and swallow — its own bug.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one price');

        CheckoutRequest::forPrices([]);
    }

    public function test_a_non_positive_charge_amount_is_refused_before_the_gateway(): void
    {
        // Otherwise the caller's own bug reaches the gateway, comes back as a 4xx,
        // and is delivered as a CashierException — a billing failure the app is
        // invited to catch and swallow. That inverts the boundary exactly.
        Cashier::extend('fake', fn () => new FakeGateway([Capability::Charges]));

        $this->expectException(InvalidArgumentException::class);
        (new User)->charge(-500, 'pm_1');
    }

    public function test_an_unsupported_operation_is_a_cashier_exception(): void
    {
        // The counter-case: the app can do nothing about a gateway that has no
        // such feature, so it must be catchable.
        $this->assertTrue(is_subclass_of(UnsupportedOperationException::class, CashierException::class));
    }

    /**
     * Every operation a gateway performs states what it can throw, and every type
     * it names is one side of the boundary or the other.
     *
     * Discovered by globbing the contracts, not by a hand-written list: a list is
     * exactly what leaves the next contract out. And the tags are resolved, not
     * grepped — "@throws" as a substring would pass on a type that does not exist,
     * or on one that belongs to neither side.
     */
    public function test_every_gateway_operation_declares_what_it_throws(): void
    {
        $contracts = $this->gatewayContracts();

        $this->assertGreaterThanOrEqual(7, count($contracts), 'The contract sweep found suspiciously few contracts.');

        foreach ($contracts as $contract) {
            foreach ((new ReflectionClass($contract))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $doc = $method->getDocComment();
                $where = "{$contract}::{$method->getName()}()";

                $this->assertIsString($doc, "{$where} has no docblock.");

                preg_match_all('/@throws\s+([^\s]+)/', $doc, $matches);
                $thrown = $matches[1];

                $this->assertNotEmpty($thrown, "{$where} does not say what it can throw.");

                foreach ($thrown as $name) {
                    $class = $this->resolve($name, $contract);

                    $this->assertTrue(class_exists($class), "{$where} declares [{$name}], which does not exist.");

                    $this->assertTrue(
                        is_a($class, CashierException::class, true) || is_a($class, InvalidArgumentException::class, true),
                        "{$where} declares [{$class}], which is on neither side of the boundary: a billing failure "
                        .'extends CashierException, a malformed argument is an InvalidArgumentException.',
                    );
                }
            }
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function gatewayContracts(): array
    {
        $contracts = [];

        foreach (glob(dirname(__DIR__, 2).'/src/Contracts/*.php') ?: [] as $file) {
            $class = 'Isapp\\CashierSupport\\Contracts\\'.basename($file, '.php');

            // The operations a gateway performs — not the value types (a checkout
            // session, a payment method type) that merely describe their results.
            if (str_ends_with($class, 'Operations') || $class === SubscriptionBuilder::class) {
                $contracts[] = $class;
            }
        }

        return $contracts;
    }

    /**
     * @return class-string
     */
    private function resolve(string $name, string $contract): string
    {
        if (class_exists($name)) {
            return $name;
        }

        foreach ([
            'Isapp\\CashierSupport\\Exceptions\\'.$name,
            '\\'.$name,
            $name,
        ] as $candidate) {
            if (class_exists($candidate)) {
                return ltrim($candidate, '\\');
            }
        }

        return $name;
    }
}
