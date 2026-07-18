<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Testing;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Contracts\IncomingWebhook;
use Isapp\CashierSupport\Contracts\SubscriptionBuilder;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\DTO\Customer;
use Isapp\CashierSupport\DTO\CustomerDetails;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\PaymentMethod;
use Isapp\CashierSupport\DTO\Refund;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\Proration;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Enums\SwapTiming;
use Money\Currency;
use PHPUnit\Framework\Assert;
use Throwable;

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
     * The request handed to the last checkout() call — lets a test see what the
     * legacy arguments normalized into.
     */
    public ?CheckoutRequest $lastCheckoutRequest = null;

    /**
     * The details handed to the last createCustomer()/updateCustomer() call — lets a test see
     * what the concern resolved out of the hooks and the options bag, which is the only way to
     * prove a name reached the gateway rather than merely being asked for.
     */
    public ?CustomerDetails $lastCustomerDetails = null;

    /**
     * What the last updateSubscriptionQuantity() call was told to land on — the only way to
     * prove increment/decrement did their arithmetic against the STORED quantity, rather
     * than merely that a number arrived.
     */
    public ?int $lastQuantity = null;

    public ?string $lastQuantityType = null;

    public ?string $lastQuantityPrice = null;

    /**
     * The proration intent the last updateSubscriptionQuantity() call was given — proof it reached
     * the gateway rather than being dropped at the guard.
     */
    public ?Proration $lastQuantityProration = null;

    /**
     * The resume date the last pauseSubscription() call was given — the only way to prove $until
     * reached the gateway rather than being dropped at the Billable gate.
     */
    public ?DateTimeInterface $lastPauseUntil = null;

    /**
     * The prices and timing the last swapSubscription() call was given — proof the caller's
     * intent reached the gateway rather than being dropped on the way through.
     *
     * @var string|array<int, string>|null
     */
    public string|array|null $lastSwapPrices = null;

    public ?SwapTiming $lastSwapTiming = null;

    public ?Proration $lastSwapProration = null;

    /**
     * The raw bytes and headers the last webhook() call was given — lets a test see
     * that the controller passed the body through untouched, which is what a signature
     * is checked against.
     */
    public ?string $lastWebhookContent = null;

    /** @var array<string, string>|null */
    public ?array $lastWebhookHeaders = null;

    /**
     * What FakeIncomingWebhook::parse() hands back.
     *
     * @var array<string, mixed>
     */
    public array $webhookPayload = ['event' => 'fake.event'];

    /**
     * Thrown from parse() instead of returning, when set. Drives the verification,
     * misconfiguration and unreadable-body paths.
     */
    public ?Throwable $webhookParseFailure = null;

    /**
     * What pipeline() returns. False is the case that matters: an event this driver
     * does not map, which must NOT throw.
     */
    public bool $webhookHandled = true;

    /**
     * Thrown from pipeline(), when set — applying failed and the delivery deserves a retry.
     */
    public ?Throwable $webhookPipelineFailure = null;

    /**
     * When set, the NEXT charge() returns a payment in this status (an incomplete state such
     * as RequiresAction) instead of Succeeded, then resets — the way a real gateway answers an
     * SCA/3DS charge before the customer has confirmed it.
     */
    public ?PaymentStatus $nextChargeStatus = null;

    /**
     * The client secret carried by that next incomplete charge — what a frontend would hand
     * back to the gateway to complete it.
     */
    public ?string $nextChargeClientSecret = null;

    /**
     * Every step of a delivery, in the order it happened. A test appends to this from a
     * listener too, which is the only way to prove the event fires BETWEEN parse and
     * pipeline rather than merely that all three happened.
     *
     * @var array<int, string>
     */
    public array $webhookCalls = [];

    /**
     * Every charge this gateway was asked to make, in order — what assertCharged() reads.
     *
     * @var array<int, Payment>
     */
    public array $charges = [];

    /**
     * Every refund this gateway processed — what assertRefunded() reads.
     *
     * @var array<int, Refund>
     */
    public array $refunds = [];

    /**
     * Every subscription actually created — appended by FakeSubscriptionBuilder::create(),
     * not by newSubscription(), because creation is the builder's act. assertSubscriptionCreated() reads it.
     *
     * @var array<int, Subscription>
     */
    public array $createdSubscriptions = [];

    /**
     * Every subscription canceled (immediately or at period end) — what assertSubscriptionCanceled() reads.
     *
     * @var array<int, Subscription>
     */
    public array $canceledSubscriptions = [];

    /**
     * Every customer created — what assertCustomerCreated() reads.
     *
     * @var array<int, Customer>
     */
    public array $createdCustomers = [];

    /**
     * Every customer updated — what assertCustomerUpdated() reads.
     *
     * @var array<int, Customer>
     */
    public array $updatedCustomers = [];

    /**
     * Every checkout request this gateway was handed — what assertCheckoutCreated() reads.
     *
     * @var array<int, CheckoutRequest>
     */
    public array $checkouts = [];

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

    public function createCustomer(Model $billable, CustomerDetails $details): Customer
    {
        $this->lastCustomerDetails = $details;

        $customer = new Customer(id: 'cus_fake', name: $details->name, email: $details->email);

        $this->createdCustomers[] = $customer;

        return $customer;
    }

    public function updateCustomer(Model $billable, CustomerDetails $details): Customer
    {
        $this->lastCustomerDetails = $details;

        $customer = new Customer(id: 'cus_fake', name: $details->name, email: $details->email);

        $this->updatedCustomers[] = $customer;

        return $customer;
    }

    public function asCustomer(Model $billable): Customer
    {
        return new Customer(id: 'cus_fake');
    }

    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment
    {
        $status = $this->nextChargeStatus ?? PaymentStatus::Succeeded;
        $clientSecret = $this->nextChargeClientSecret;

        $this->nextChargeStatus = null;
        $this->nextChargeClientSecret = null;

        $payment = new Payment(
            id: 'pay_fake',
            amount: $amount,
            currency: new Currency('EUR'),
            status: $status,
            clientSecret: $clientSecret,
        );

        $this->charges[] = $payment;

        return $payment;
    }

    public function refund(Model $billable, string $paymentId, array $options = []): Refund
    {
        $refund = new Refund(id: 're_fake', paymentId: $paymentId, amount: 0, currency: new Currency('EUR'));

        $this->refunds[] = $refund;

        return $refund;
    }

    public function newSubscription(Model $billable, string $type, string|array $prices): SubscriptionBuilder
    {
        return $this->lastBuilder = new FakeSubscriptionBuilder($this, $type);
    }

    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription
    {
        $subscription = new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Canceled);

        $this->canceledSubscriptions[] = $subscription;

        return $subscription;
    }

    public function cancelSubscriptionNow(Model $billable, string $type = 'default'): Subscription
    {
        $subscription = new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Canceled);

        $this->canceledSubscriptions[] = $subscription;

        return $subscription;
    }

    public function resumeSubscription(Model $billable, string $type = 'default'): Subscription
    {
        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Active);
    }

    public function pauseSubscription(
        Model $billable,
        string $type = 'default',
        ?DateTimeInterface $until = null,
    ): Subscription {
        $this->lastPauseUntil = $until;

        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Paused);
    }

    public function swapSubscription(Model $billable, string $type, string|array $prices, SwapTiming $timing = SwapTiming::Immediate, Proration $proration = Proration::Prorate, array $options = []): Subscription
    {
        $this->lastSwapPrices = $prices;
        $this->lastSwapTiming = $timing;
        $this->lastSwapProration = $proration;

        return new Subscription(id: 'sub_fake', type: $type, status: SubscriptionStatus::Active);
    }

    public function updateSubscriptionQuantity(Model $billable, string $type, int $quantity, string $price, Proration $proration = Proration::Prorate): Subscription
    {
        $this->lastQuantity = $quantity;
        $this->lastQuantityType = $type;
        $this->lastQuantityPrice = $price;
        $this->lastQuantityProration = $proration;

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

    public function storeInvoice(Model $billable, string $invoiceId, array $data = [], ?string $disk = null, ?string $path = null): string
    {
        return $path ?? 'invoices/invoice-'.$invoiceId.'.pdf';
    }

    /**
     * The stored payment methods this gateway reports.
     *
     * @var array<int, PaymentMethod>
     */
    public array $storedPaymentMethods = [];

    /**
     * The default this gateway reports, if any.
     */
    public ?PaymentMethod $storedDefaultPaymentMethod = null;

    /**
     * Every id handed to deletePaymentMethod(), in order — the only way to prove a bulk
     * delete reached each one rather than merely returning quietly.
     *
     * @var array<int, string>
     */
    public array $deletedPaymentMethods = [];

    public function paymentMethods(Model $billable): array
    {
        return $this->storedPaymentMethods;
    }

    public function defaultPaymentMethod(Model $billable): ?PaymentMethod
    {
        return $this->storedDefaultPaymentMethod;
    }

    public function addPaymentMethod(Model $billable, string $paymentMethod): PaymentMethod
    {
        return new PaymentMethod(id: $paymentMethod, type: FakePaymentMethodType::Card);
    }

    public function deletePaymentMethod(Model $billable, string $paymentMethodId): void
    {
        $this->deletedPaymentMethods[] = $paymentMethodId;
    }

    public function checkout(Model $billable, CheckoutRequest $request): CheckoutSession
    {
        $this->lastCheckoutRequest = $request;
        $this->checkouts[] = $request;

        return new FakeCheckoutSession(id: 'cs_fake');
    }

    public function webhook(string $content, array $headers): IncomingWebhook
    {
        $this->lastWebhookContent = $content;
        $this->lastWebhookHeaders = $headers;

        return new FakeIncomingWebhook($this);
    }

    /**
     * Assert a charge was recorded — optionally one the callback accepts.
     *
     * @param  (callable(Payment): bool)|null  $callback
     */
    public function assertCharged(?callable $callback = null): void
    {
        $this->assertRecorded($this->charges, $callback, 'Expected a charge to have been recorded on the fake gateway, but none matched.');
    }

    /**
     * Assert no charge was recorded — optionally none the callback accepts.
     *
     * @param  (callable(Payment): bool)|null  $callback
     */
    public function assertNotCharged(?callable $callback = null): void
    {
        $this->assertNotRecorded($this->charges, $callback, 'Expected no charge to have been recorded on the fake gateway, but one matched.');
    }

    /**
     * Assert a refund was recorded — optionally one the callback accepts.
     *
     * @param  (callable(Refund): bool)|null  $callback
     */
    public function assertRefunded(?callable $callback = null): void
    {
        $this->assertRecorded($this->refunds, $callback, 'Expected a refund to have been recorded on the fake gateway, but none matched.');
    }

    /**
     * Assert a subscription was created — optionally one the callback accepts. Creation is the
     * builder's create()/add() call, not newSubscription(), so this fires only once a build completes.
     *
     * @param  (callable(Subscription): bool)|null  $callback
     */
    public function assertSubscriptionCreated(?callable $callback = null): void
    {
        $this->assertRecorded($this->createdSubscriptions, $callback, 'Expected a subscription to have been created on the fake gateway, but none matched.');
    }

    /**
     * Assert no subscription was created — optionally none the callback accepts.
     *
     * @param  (callable(Subscription): bool)|null  $callback
     */
    public function assertSubscriptionNotCreated(?callable $callback = null): void
    {
        $this->assertNotRecorded($this->createdSubscriptions, $callback, 'Expected no subscription to have been created on the fake gateway, but one matched.');
    }

    /**
     * Assert a subscription was canceled — optionally one the callback accepts.
     *
     * @param  (callable(Subscription): bool)|null  $callback
     */
    public function assertSubscriptionCanceled(?callable $callback = null): void
    {
        $this->assertRecorded($this->canceledSubscriptions, $callback, 'Expected a subscription to have been canceled on the fake gateway, but none matched.');
    }

    /**
     * Assert a customer was created — optionally one the callback accepts.
     *
     * @param  (callable(Customer): bool)|null  $callback
     */
    public function assertCustomerCreated(?callable $callback = null): void
    {
        $this->assertRecorded($this->createdCustomers, $callback, 'Expected a customer to have been created on the fake gateway, but none matched.');
    }

    /**
     * Assert a customer was updated — optionally one the callback accepts.
     *
     * @param  (callable(Customer): bool)|null  $callback
     */
    public function assertCustomerUpdated(?callable $callback = null): void
    {
        $this->assertRecorded($this->updatedCustomers, $callback, 'Expected a customer to have been updated on the fake gateway, but none matched.');
    }

    /**
     * Assert a checkout was created — optionally one whose request the callback accepts.
     *
     * @param  (callable(CheckoutRequest): bool)|null  $callback
     */
    public function assertCheckoutCreated(?callable $callback = null): void
    {
        $this->assertRecorded($this->checkouts, $callback, 'Expected a checkout to have been created on the fake gateway, but none matched.');
    }

    /**
     * Assert at least one record matches — any record when no callback is given.
     *
     * @param  array<int, mixed>  $records
     */
    private function assertRecorded(array $records, ?callable $callback, string $message): void
    {
        $matches = $callback === null ? $records : array_filter($records, $callback);

        Assert::assertNotEmpty($matches, $message);
    }

    /**
     * Assert no record matches — no record at all when no callback is given.
     *
     * @param  array<int, mixed>  $records
     */
    private function assertNotRecorded(array $records, ?callable $callback, string $message): void
    {
        $matches = $callback === null ? $records : array_filter($records, $callback);

        Assert::assertEmpty($matches, $message);
    }
}
