<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\Fixtures\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\SecondaryDriverUser;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

class BillableTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::extend('fake', fn () => new FakeGateway([
            Capability::Charges,
            Capability::Subscriptions,
        ]));
    }

    public function test_billable_delegates_a_supported_operation(): void
    {
        $payment = (new User)->charge(1500, 'pm_visa');

        $this->assertSame(1500, $payment->amount);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
    }

    public function test_unsupported_capability_throws(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        (new User)->checkout('price_monthly');
    }

    public function test_unsupported_exception_carries_the_capability(): void
    {
        try {
            (new User)->paymentMethods();
            $this->fail('Expected UnsupportedOperationException.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PaymentMethodsList, $e->capability);
        }
    }

    public function test_model_can_use_a_non_default_driver(): void
    {
        Cashier::extend('secondary', fn () => new FakeGateway([Capability::Charges]));

        $payment = (new SecondaryDriverUser)->charge(500, 'pm_visa');

        $this->assertSame(500, $payment->amount);
    }

    public function test_non_default_driver_still_gates_capabilities(): void
    {
        Cashier::extend('secondary', fn () => new FakeGateway([Capability::Charges]));

        $this->expectException(UnsupportedOperationException::class);

        (new SecondaryDriverUser)->newSubscription('default', 'price_monthly');
    }
}
