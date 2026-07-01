<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\CashierManager;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
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

    public function test_provider_returns_the_gateway_instance(): void
    {
        $this->assertInstanceOf(FakeGateway::class, Cashier::provider());
        $this->assertInstanceOf(FakeGateway::class, Cashier::provider('fake'));
    }

    public function test_supports_and_ensure_supports(): void
    {
        $this->assertTrue(Cashier::supports(Capability::Charges));
        $this->assertFalse(Cashier::supports(Capability::Checkout));

        Cashier::ensureSupports(Capability::Charges);

        $this->expectException(UnsupportedOperationException::class);
        Cashier::ensureSupports(Capability::Checkout);
    }

    public function test_a_missing_default_driver_is_a_configuration_error(): void
    {
        config()->set('cashier-support.default', null);

        $this->expectException(InvalidConfigurationException::class);

        Cashier::provider();
    }
}
