<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Exceptions\IncompletePaymentException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * A charge that needs 3DS/SCA must be driveable to completion: it surfaces as a catchable
 * IncompletePaymentException carrying the client secret the frontend needs, and a subsequent
 * (post-authentication) attempt completes. This is issue #35's acceptance criterion.
 */
class IncompletePaymentTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    public function test_an_sca_charge_surfaces_the_client_secret_then_completes(): void
    {
        $gateway = new FakeGateway([Capability::Charges]);
        Cashier::extend('fake', fn () => $gateway);

        // The gateway answers the first charge with "requires action", the way a real gateway
        // does before the customer has passed 3DS.
        $gateway->nextChargeStatus = PaymentStatus::RequiresAction;
        $gateway->nextChargeClientSecret = 'pi_secret_123';

        try {
            (new User)->charge(1000, 'pm_1');
            $this->fail('An incomplete charge must throw IncompletePaymentException.');
        } catch (IncompletePaymentException $e) {
            $this->assertSame('pay_fake', $e->paymentId);
            $this->assertSame('pi_secret_123', $e->clientSecret, 'The frontend needs the client secret to resume the payment.');
            $this->assertSame(PaymentStatus::RequiresAction, $e->status);
        }

        // The customer completed authentication on the client with that secret; the next
        // attempt clears and the payment succeeds.
        $payment = (new User)->charge(1000, 'pm_1');

        $this->assertTrue($payment->status->isSuccessful());
        $this->assertNull($payment->clientSecret);
    }

    public function test_charge_surfaces_every_incomplete_state_from_the_gateway(): void
    {
        $gateway = new FakeGateway([Capability::Charges]);
        Cashier::extend('fake', fn () => $gateway);

        foreach ([
            PaymentStatus::RequiresPaymentMethod,
            PaymentStatus::RequiresAction,
            PaymentStatus::RequiresConfirmation,
        ] as $state) {
            $gateway->nextChargeStatus = $state;
            $gateway->nextChargeClientSecret = 'secret_for_'.$state->value;

            try {
                (new User)->charge(1000, 'pm_1');
                $this->fail("A {$state->value} charge must throw IncompletePaymentException.");
            } catch (IncompletePaymentException $e) {
                $this->assertSame($state, $e->status);
                $this->assertSame('secret_for_'.$state->value, $e->clientSecret);
                $this->assertSame('pay_fake', $e->paymentId);
            }
        }
    }

    public function test_each_incomplete_state_carries_its_resumable_data(): void
    {
        $action = IncompletePaymentException::requiresAction('pay_1', 'secret_1');
        $this->assertSame('pay_1', $action->paymentId);
        $this->assertSame('secret_1', $action->clientSecret);
        $this->assertSame(PaymentStatus::RequiresAction, $action->status);

        $confirmation = IncompletePaymentException::requiresConfirmation('pay_2', 'secret_2');
        $this->assertSame('pay_2', $confirmation->paymentId);
        $this->assertSame('secret_2', $confirmation->clientSecret);
        $this->assertSame(PaymentStatus::RequiresConfirmation, $confirmation->status);

        $method = IncompletePaymentException::requiresPaymentMethod('pay_3', 'secret_3');
        $this->assertSame('pay_3', $method->paymentId);
        $this->assertSame('secret_3', $method->clientSecret);
        $this->assertSame(PaymentStatus::RequiresPaymentMethod, $method->status);
    }
}
