<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\DTO\SubscriptionItem;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Tests\Fixtures\FakePaymentMethodType;
use Isapp\CashierSupport\Tests\TestCase;

class DtoTest extends TestCase
{
    public function test_payment_round_trips_through_array(): void
    {
        $payment = Payment::from([
            'id' => 'pay_1',
            'amount' => 1500,
            'currency' => 'EUR',
            'status' => 'succeeded',
        ]);

        $this->assertSame(1500, $payment->amount);
        $this->assertSame(Currency::EUR, $payment->currency);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        $this->assertSame([
            'id' => 'pay_1',
            'amount' => 1500,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'paymentMethodId' => null,
            'createdAt' => null,
        ], $payment->toArray());
    }

    public function test_customer_round_trips(): void
    {
        $customer = Customer::from(['id' => 'cus_1', 'name' => 'Ada', 'email' => 'ada@example.com']);

        $this->assertSame('cus_1', $customer->id);
        $this->assertSame('Ada', $customer->name);
    }

    public function test_subscription_nests_items(): void
    {
        $subscription = new Subscription(
            id: 'sub_1',
            name: 'default',
            status: SubscriptionStatus::Active,
            items: [new SubscriptionItem(id: 'si_1', price: 'price_monthly', quantity: 2)],
        );

        $array = $subscription->toArray();

        $this->assertSame('active', $array['status']);
        $this->assertCount(1, $array['items']);
        $this->assertSame('price_monthly', $array['items'][0]['price']);
        $this->assertSame(2, $array['items'][0]['quantity']);
    }

    public function test_payment_method_holds_provider_type_contract(): void
    {
        $method = new PaymentMethod(
            id: 'pm_1',
            type: FakePaymentMethodType::Card,
            brand: 'visa',
            last4: '4242',
        );

        $array = $method->toArray();

        $this->assertSame('card', $array['type']);
        $this->assertSame('4242', $array['last4']);
    }
}
