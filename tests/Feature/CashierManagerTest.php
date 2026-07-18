<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\CashierManager;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\GuardedProvider;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\TestCase;

class CashierManagerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::extend('fake', fn () => new FakeGateway([Capability::Charges]));
    }

    protected function tearDown(): void
    {
        CashierManager::flushMacros();

        parent::tearDown();
    }

    public function test_macros_are_callable_through_the_facade(): void
    {
        Cashier::macro('greeting', fn (): string => 'pong');

        $this->assertTrue(Cashier::hasMacro('greeting'));
        $this->assertSame('pong', Cashier::greeting());
    }

    public function test_non_macro_calls_still_forward_to_the_default_driver(): void
    {
        $this->assertSame([Capability::Charges], Cashier::capabilities());
    }

    public function test_provider_wraps_the_driver_in_the_capability_guard(): void
    {
        // provider() no longer hands back the raw driver — it wraps it in GuardedProvider so every
        // operation is gated at one boundary. The guard forwards capability queries to the driver.
        $this->assertInstanceOf(GuardedProvider::class, Cashier::provider());
        $this->assertInstanceOf(GuardedProvider::class, Cashier::provider('fake'));

        $this->assertSame([Capability::Charges], Cashier::provider()->capabilities());
        $this->assertTrue(Cashier::provider()->supports(Capability::Charges));
    }

    public function test_a_driver_without_the_webhook_registration_interface_has_no_registrar(): void
    {
        // webhookRegistrar() resolves the opt-in RegistersWebhooks off the raw driver, or null —
        // it is the one sanctioned reason to look past the guard, and the guard cannot carry it.
        $this->assertNull(Cashier::webhookRegistrar());
    }

    public function test_supports_and_ensure_supports(): void
    {
        $this->assertTrue(Cashier::supports(Capability::Charges));
        $this->assertFalse(Cashier::supports(Capability::CheckoutPrices));

        Cashier::ensureSupports(Capability::Charges);

        $this->expectException(UnsupportedOperationException::class);
        Cashier::ensureSupports(Capability::CheckoutPrices);
    }

    public function test_a_missing_default_driver_is_a_configuration_error(): void
    {
        config()->set('cashier-support.default', null);

        $this->expectException(InvalidConfigurationException::class);

        Cashier::provider();
    }
}
