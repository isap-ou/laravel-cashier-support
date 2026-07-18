<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Unit;

use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\InvoiceLine;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\DTO\SubscriptionItem;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Testing\FakePaymentMethodType;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;

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
        $this->assertSame('EUR', $payment->currency->getCode());
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        $this->assertSame([
            'id' => 'pay_1',
            'amount' => 1500,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'paymentMethodId' => null,
            'createdAt' => null,
            'clientSecret' => null,
        ], $payment->toArray());
    }

    public function test_payment_exposes_sca_predicates_and_the_client_secret(): void
    {
        $incomplete = Payment::from([
            'id' => 'pay_2',
            'amount' => 1500,
            'currency' => 'EUR',
            'status' => 'requires_action',
            'clientSecret' => 'pi_secret_123',
        ]);

        $this->assertTrue($incomplete->requiresAction());
        $this->assertFalse($incomplete->requiresConfirmation());
        $this->assertFalse($incomplete->requiresPaymentMethod());
        $this->assertSame('pi_secret_123', $incomplete->clientSecret);

        $succeeded = Payment::from([
            'id' => 'pay_3',
            'amount' => 1500,
            'currency' => 'EUR',
            'status' => 'succeeded',
        ]);

        $this->assertFalse($succeeded->requiresAction());
        $this->assertFalse($succeeded->requiresConfirmation());
        $this->assertFalse($succeeded->requiresPaymentMethod());
        $this->assertNull($succeeded->clientSecret);
    }

    public function test_customer_round_trips(): void
    {
        $customer = Customer::from(['id' => 'cus_1', 'name' => 'Ada', 'email' => 'ada@example.com']);

        $this->assertSame('cus_1', $customer->id);
        $this->assertSame('Ada', $customer->name);
    }

    public function test_customer_details_lifts_the_typed_fields_out_of_the_bag(): void
    {
        $details = CustomerDetails::fromOptions([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'phone' => '+3531234567',
        ]);

        $this->assertSame('Ada', $details->name);
        $this->assertSame('ada@example.com', $details->email);
        $this->assertSame(['phone' => '+3531234567'], $details->options, 'What support has no concept for stays in the named hatch — it is not support\'s to understand, but it is support\'s not to lose.');
    }

    public function test_customer_details_refuses_a_non_string_name(): void
    {
        // A programmer error, so it raises SPL's InvalidArgumentException and is meant to be
        // fixed, not caught (.claude/rules/exceptions.md). The first draft rerouted it into
        // $options instead, which was worse than either obvious wrong answer: the hook then
        // filled the typed field and a driver got name: 'Ada' beside options: ['name' => 42],
        // resolving one field two ways by array-merge order.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A customer's [name] must be a string, array given.");

        CustomerDetails::fromOptions(['name' => ['Ada', 'Lovelace']]);
    }

    public function test_customer_details_refuses_a_non_string_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A customer's [email] must be a string, int given.");

        CustomerDetails::fromOptions(['email' => 42]);
    }

    public function test_customer_details_treats_an_explicit_null_as_not_specified(): void
    {
        // Not an error: the class note says null MEANS "not specified", so raising here would
        // contradict the type's own contract. The key is still consumed rather than left in the
        // bag — one field must never arrive at a driver twice.
        $details = CustomerDetails::fromOptions(['name' => null, 'phone' => '+353']);

        $this->assertNull($details->name);
        $this->assertSame(['phone' => '+353'], $details->options);
    }

    public function test_customer_details_defaults_to_nothing_specified(): void
    {
        $details = CustomerDetails::fromOptions([]);

        $this->assertNull($details->name);
        $this->assertNull($details->email);
        $this->assertSame([], $details->options);
    }

    public function test_subscription_nests_items(): void
    {
        $subscription = new Subscription(
            id: 'sub_1',
            type: 'default',
            status: SubscriptionStatus::Active,
            items: [new SubscriptionItem(id: 'si_1', price: 'price_monthly', quantity: 2)],
        );

        $array = $subscription->toArray();

        $this->assertSame('active', $array['status']);
        $this->assertCount(1, $array['items']);
        $this->assertSame('price_monthly', $array['items'][0]['price']);
        $this->assertSame(2, $array['items'][0]['quantity']);
    }

    public function test_invoice_line_carries_optional_tax_fields(): void
    {
        // The old three-argument positional call still works — the tax fields are appended
        // and default to null, so existing callers are unaffected.
        $bare = new InvoiceLine('Pro plan', 1000, 2);

        $this->assertNull($bare->unitAmount);
        $this->assertNull($bare->taxAmount);
        $this->assertNull($bare->taxRate);

        $taxed = new InvoiceLine(
            description: 'Pro plan',
            amount: 1000,
            quantity: 2,
            unitAmount: 500,
            taxAmount: 200,
            taxRate: 2000,
        );

        $this->assertSame([
            'description' => 'Pro plan',
            'amount' => 1000,
            'quantity' => 2,
            'unitAmount' => 500,
            'taxAmount' => 200,
            'taxRate' => 2000,
        ], $taxed->toArray());
    }

    public function test_invoice_carries_the_optional_money_breakdown(): void
    {
        // Amount stays the canonical total; the breakdown is optional and defaults to null.
        $plain = new Invoice(
            id: 'in_1',
            amount: 1200,
            currency: new Currency('EUR'),
            status: PaymentStatus::Succeeded,
        );

        $this->assertNull($plain->subtotal);
        $this->assertNull($plain->tax);
        $this->assertNull($plain->discount);

        $withBreakdown = new Invoice(
            id: 'in_2',
            amount: 1200,
            currency: new Currency('EUR'),
            status: PaymentStatus::Succeeded,
            subtotal: 1000,
            tax: 300,
            discount: 100,
        );

        $array = $withBreakdown->toArray();

        $this->assertSame(1200, $array['amount']);
        $this->assertSame(1000, $array['subtotal']);
        $this->assertSame(300, $array['tax']);
        $this->assertSame(100, $array['discount']);
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
