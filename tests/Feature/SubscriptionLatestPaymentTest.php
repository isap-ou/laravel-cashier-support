<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;

/**
 * Billable::subscriptionLatestPayment(): the read that lets an app complete a subscription created
 * incomplete/pending. The returned Payment carries the clientSecret the client SDK finishes the
 * first charge with (Stripe PaymentIntent client_secret, a Revolut setup-order token). It carries
 * no gate of its own: it delegates through the capability-guarded Cashier::provider(), so a gateway
 * that cannot read a payment refuses there. It lives on Billable, not Models\Subscription, because
 * the Models deptrac layer may not depend on a DTO (like asCustomer()/defaultPaymentMethod()).
 */
class SubscriptionLatestPaymentTest extends TestCase
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

    private function user(): User
    {
        /** @var User */
        return User::create(['name' => 'Ada']);
    }

    public function test_it_returns_the_payment_the_gateway_reports_with_its_client_secret(): void
    {
        $gateway = $this->driverSupporting([Capability::SubscriptionLatestPayment]);
        $gateway->nextSubscriptionPayment = new Payment(
            id: 'pay_setup',
            amount: 1500,
            currency: new Currency('EUR'),
            status: PaymentStatus::RequiresPaymentMethod,
            clientSecret: 'tok_widget',
        );

        $payment = $this->user()->subscriptionLatestPayment('default');

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame('tok_widget', $payment->clientSecret);
        $this->assertTrue($payment->requiresPaymentMethod());
    }

    public function test_it_is_null_when_the_gateway_reports_no_outstanding_payment(): void
    {
        $this->driverSupporting([Capability::SubscriptionLatestPayment]);

        $this->assertNull($this->user()->subscriptionLatestPayment('default'));
    }

    public function test_a_gateway_without_the_capability_refuses_by_that_name(): void
    {
        $this->driverSupporting([Capability::Subscriptions]);

        try {
            $this->user()->subscriptionLatestPayment('default');
            $this->fail('Expected an UnsupportedOperationException.');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SubscriptionLatestPayment, $e->capability);
        }
    }
}
