<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\DTO\WebhookPayload;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\WebhookEvent;

/**
 * An in-memory gateway provider for tests with a configurable capability set.
 */
class FakeGateway implements GatewayProvider
{
    /**
     * The builder handed to the last newSubscription() call — lets a test see
     * what a decorator actually forwarded.
     */
    public ?FakeSubscriptionBuilder $lastBuilder = null;

    /**
     * @param  array<int, Capability>  $capabilities
     */
    public function __construct(private array $capabilities = []) {}

    /**
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function createCustomer(Model $billable, array $options = []): Customer
    {
        return new Customer(id: 'cus_fake');
    }

    public function asCustomer(Model $billable): Customer
    {
        return new Customer(id: 'cus_fake');
    }

    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment
    {
        return new Payment(id: 'pay_fake', amount: $amount, currency: Currency::EUR, status: PaymentStatus::Succeeded);
    }

    public function refund(Model $billable, string $paymentId, array $options = []): Refund
    {
        return new Refund(id: 're_fake', paymentId: $paymentId, amount: 0, currency: Currency::EUR);
    }

    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder
    {
        return $this->lastBuilder = new FakeSubscriptionBuilder($type);
    }

    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Canceled);
    }

    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Canceled);
    }

    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Active);
    }

    public function pauseSubscription(Model $billable, string $type = 'default'): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Paused);
    }

    public function swapSubscription(Model $billable, string $type, string|array $prices, array $options = []): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Active);
    }

    public function invoices(Model $billable, array $parameters = []): array
    {
        return [];
    }

    public function findInvoice(Model $billable, string $invoiceId): ?Invoice
    {
        return null;
    }

    public function downloadInvoice(Model $billable, string $invoiceId, array $data = []): Response
    {
        return new Response('%PDF-fake');
    }

    public function paymentMethods(Model $billable): array
    {
        return [];
    }

    public function defaultPaymentMethod(Model $billable): ?PaymentMethod
    {
        return null;
    }

    public function addPaymentMethod(Model $billable, string $paymentMethod): PaymentMethod
    {
        return new PaymentMethod(id: $paymentMethod, type: FakePaymentMethodType::Card);
    }

    public function deletePaymentMethod(Model $billable, string $paymentMethodId): void {}

    public function checkout(Model $billable, array|string $items, array $options = []): CheckoutSession
    {
        return new FakeCheckoutSession(id: 'cs_fake');
    }

    public function verifyWebhook(string $payload, array $headers): void {}

    public function parseWebhook(string $payload, array $headers): WebhookPayload
    {
        return new WebhookPayload(event: WebhookEvent::PaymentSucceeded, id: 'evt_fake');
    }
}
