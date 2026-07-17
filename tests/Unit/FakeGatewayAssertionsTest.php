<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * The recorded-operation assertions the fake ships for a host app. They read the fake's own
 * spy arrays — there is no driver here, so no event is dispatched to assert against.
 */
class FakeGatewayAssertionsTest extends TestCase
{
    private FakeGateway $gateway;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
        $this->user = new User;
    }

    public function test_assert_charged_passes_after_a_charge_and_honours_the_callback(): void
    {
        $this->gateway->charge($this->user, 1000, 'pm_x');

        $this->gateway->assertCharged();
        $this->gateway->assertCharged(fn (Payment $p) => $p->amount === 1000);

        $this->assertAssertionFails(fn () => $this->gateway->assertCharged(fn (Payment $p) => $p->amount === 999));
    }

    public function test_assert_not_charged_is_the_inverse(): void
    {
        $this->gateway->assertNotCharged();

        $this->gateway->charge($this->user, 1000, 'pm_x');

        // A charge exists, so the unqualified negative fails...
        $this->assertAssertionFails(fn () => $this->gateway->assertNotCharged());

        // ...but a callback that no charge matches still holds.
        $this->gateway->assertNotCharged(fn (Payment $p) => $p->amount === 999);
    }

    public function test_assert_refunded(): void
    {
        $this->assertAssertionFails(fn () => $this->gateway->assertRefunded());

        $this->gateway->refund($this->user, 'pay_x');

        $this->gateway->assertRefunded();
    }

    public function test_assert_subscription_created_fires_only_once_the_build_completes(): void
    {
        // newSubscription() alone hands back a builder — nothing is created yet.
        $builder = $this->gateway->newSubscription($this->user, 'default', 'price_monthly');
        $this->gateway->assertSubscriptionNotCreated();

        $builder->create();

        $this->gateway->assertSubscriptionCreated();
        $this->gateway->assertSubscriptionCreated(fn (Subscription $s) => $s->type === 'default');
        $this->assertAssertionFails(fn () => $this->gateway->assertSubscriptionNotCreated());
    }

    public function test_assert_subscription_created_also_covers_the_builder_add_path(): void
    {
        $this->gateway->newSubscription($this->user, 'team', 'price_seat')->add();

        $this->gateway->assertSubscriptionCreated(fn (Subscription $s) => $s->type === 'team');
    }

    public function test_assert_subscription_canceled_covers_both_cancel_and_cancel_now(): void
    {
        $this->gateway->cancelSubscription($this->user, 'default');
        $this->gateway->assertSubscriptionCanceled(fn (Subscription $s) => $s->type === 'default');

        $other = new FakeGateway;
        $other->cancelSubscriptionNow($this->user, 'team');
        $other->assertSubscriptionCanceled(fn (Subscription $s) => $s->type === 'team');
    }

    public function test_assert_customer_created_and_updated_are_distinct(): void
    {
        $this->gateway->createCustomer($this->user, new CustomerDetails(name: 'Ada', email: 'ada@example.com'));
        $this->gateway->assertCustomerCreated(fn ($c) => $c->name === 'Ada');
        $this->assertAssertionFails(fn () => $this->gateway->assertCustomerUpdated());

        $this->gateway->updateCustomer($this->user, new CustomerDetails(email: 'ada@new.example.com'));
        $this->gateway->assertCustomerUpdated(fn ($c) => $c->email === 'ada@new.example.com');
    }

    public function test_assert_checkout_created(): void
    {
        $this->assertAssertionFails(fn () => $this->gateway->assertCheckoutCreated());

        $this->gateway->checkout($this->user, CheckoutRequest::forAmount(1000, new Currency('EUR')));

        $this->gateway->assertCheckoutCreated();
        $this->gateway->assertCheckoutCreated(fn (CheckoutRequest $r) => $r->amount === 1000);
    }

    /**
     * Assert that invoking $probe raises a PHPUnit assertion failure — the way we prove a fake
     * assertion actually fails, rather than passing silently.
     */
    private function assertAssertionFails(callable $probe): void
    {
        try {
            $probe();
        } catch (ExpectationFailedException $e) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the fake-gateway assertion to fail, but it passed.');
    }
}
