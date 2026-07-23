<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Testing;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\CashierSupportServiceProvider;
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
use Isapp\CashierSupport\Enums\SwapTiming;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Money\Currency;
use Orchestra\Testbench\TestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The contract a driver must honour, expressed as a test any driver can extend.
 *
 * A driver supplies its configured gateway and a billable model; this suite then holds the
 * gateway to the guarantees that make providers interchangeable — the ones true of EVERY
 * conformant gateway, independent of how a driver couples its capabilities to its methods:
 *
 *   - `capabilities()` and `supports()` agree, in both directions;
 *   - every operation is *answerable* — invoking it returns the contract's declared type, or
 *     throws `UnsupportedOperationException` (a catchable `CashierException`), never a raw
 *     `TypeError`. This is the drop-in guarantee: a gateway that cannot do X still ANSWERS X;
 *   - a capability the gateway *declares* supported that maps to concrete methods
 *     (`Capability::methods()` — the method-derived capabilities) actually works: the method
 *     returns its type rather than refusing. This catches a gateway that lies about support.
 *
 * ## What this suite deliberately does NOT verify
 *
 * It drives the gateway **directly**, which is the wrong altitude for two classes of capability,
 * so it leaves them alone rather than assert something unsound:
 *
 *   - **`¬supports(cap) ⇒ throws` is NOT asserted.** Re-asserting a capability inside the gateway
 *     is *optional* (`.claude/rules/capabilities.md`: "may/should"); the mandatory gate is at
 *     `Billable`. A driver that swaps only at period end may still return a `Subscription` when its
 *     `swapSubscription()` is called with `Immediate` directly — that is conformant, and asserting a
 *     throw here would false-fail it.
 *   - **Timing/shape intent** (swap immediate vs at-period-end, checkout prices vs amount) and the
 *     **builder-setter capabilities** (`Trials`, `Quantity`, `Metadata`, `Taxes`, `Discounts`) are
 *     gated at `Billable`, not the gateway — `Capability::methods()` maps them to `[]`. A driver
 *     proves those through its own `Billable`-level tests, not here.
 *
 * A signature-verifying driver (mandatory in this package) must override `sampleWebhook()` with a
 * payload its gateway accepts, or `webhook()`'s delivery will refuse the default body.
 *
 * The operation table is `operations()`; a driver whose invocation shapes differ can override it.
 */
abstract class GatewayConformanceTestCase extends TestCase
{
    /**
     * The gateway under test, configured exactly as the driver ships it.
     */
    abstract protected function gateway(): GatewayProvider;

    /**
     * A billable model to hand each operation. The fake ignores it and a refusal throws before
     * touching it, so an unsaved instance is fine for conformance.
     */
    abstract protected function billable(): Model;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            CashierSupportServiceProvider::class,
        ];
    }

    protected function samplePriceId(): string
    {
        return 'price_conformance';
    }

    protected function samplePaymentMethod(): string
    {
        return 'pm_conformance';
    }

    protected function samplePaymentId(): string
    {
        return 'pay_conformance';
    }

    protected function sampleInvoiceId(): string
    {
        return 'in_conformance';
    }

    /**
     * A webhook body and headers to feed `webhook()`. The default is deliberately opaque; a driver
     * with signature verification must override it with a payload its gateway would accept.
     *
     * @return array{content: string, headers: array<string, string>}
     */
    protected function sampleWebhook(): array
    {
        return ['content' => '{}', 'headers' => []];
    }

    public function test_capabilities_are_all_valid_and_unique(): void
    {
        $capabilities = $this->gateway()->capabilities();

        foreach ($capabilities as $capability) {
            $this->assertInstanceOf(Capability::class, $capability);
        }

        $this->assertSameSize($capabilities, array_unique($capabilities, SORT_REGULAR), 'capabilities() must not repeat a capability.');
    }

    public function test_supports_agrees_with_capabilities_in_both_directions(): void
    {
        $gateway = $this->gateway();
        $declared = $gateway->capabilities();

        foreach (Capability::cases() as $capability) {
            $this->assertSame(
                in_array($capability, $declared, true),
                $gateway->supports($capability),
                "supports({$capability->value}) must agree with the capabilities() list.",
            );
        }
    }

    public function test_every_operation_is_answerable(): void
    {
        $gateway = $this->gateway();
        $billable = $this->billable();

        foreach ($this->operations() as $operation) {
            try {
                $result = ($operation['invoke'])($gateway, $billable);
            } catch (UnsupportedOperationException) {
                // A gateway that cannot do this still ANSWERED it — catchably. That is conformant.
                $this->addToAssertionCount(1);

                continue;
            }

            $this->assertOperationReturnsDeclaredType($operation, $result);
        }
    }

    public function test_declared_method_capabilities_actually_work(): void
    {
        $gateway = $this->gateway();
        $billable = $this->billable();

        $required = $this->methodsRequiredToWork($gateway);

        if ($required === []) {
            $this->markTestSkipped('Gateway declares no method-derived capability.');
        }

        foreach ($this->operations() as $operation) {
            if (! in_array($operation['method'], $required, true)) {
                continue;
            }

            try {
                $result = ($operation['invoke'])($gateway, $billable);
            } catch (UnsupportedOperationException) {
                $this->fail("[{$operation['label']}] is under a declared-supported capability, so it must not refuse.");
            }

            $this->assertOperationReturnsDeclaredType($operation, $result);
        }
    }

    /**
     * The method names the gateway must implement for real, derived from the capabilities it
     * declares and `Capability::methods()` — the same map `BaseGateway::supports()` reads. Only the
     * method-derived capabilities contribute; the intent/builder-setter ones map to `[]`.
     *
     * @return array<int, string>
     */
    private function methodsRequiredToWork(GatewayProvider $gateway): array
    {
        $methods = [];

        foreach ($gateway->capabilities() as $capability) {
            foreach ($capability->methods() as $method) {
                $methods[] = $method;
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * Every concrete invocation the suite performs, each with the type its contract declares. The
     * `method` is the `GatewayProvider` method it exercises (matched against `Capability::methods()`);
     * timing/shape variants share a method name and appear more than once so both are answered.
     *
     * @return array<int, array{
     *     method: string,
     *     label: string,
     *     invoke: callable(GatewayProvider, Model): mixed,
     *     returns?: class-string|'array'|'string',
     *     nullable?: bool,
     *     void?: bool,
     * }>
     */
    protected function operations(): array
    {
        return [
            ['method' => 'charge', 'label' => 'charge', 'returns' => Payment::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->charge($b, 1000, $this->samplePaymentMethod())],
            ['method' => 'refund', 'label' => 'refund', 'returns' => Refund::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->refund($b, $this->samplePaymentId())],
            ['method' => 'createCustomer', 'label' => 'createCustomer', 'returns' => Customer::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->createCustomer($b, new CustomerDetails(name: 'Conformance', email: 'conformance@example.com'))],
            ['method' => 'asCustomer', 'label' => 'asCustomer', 'returns' => Customer::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->asCustomer($b)],
            ['method' => 'updateCustomer', 'label' => 'updateCustomer', 'returns' => Customer::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->updateCustomer($b, new CustomerDetails(email: 'updated@example.com'))],
            ['method' => 'newSubscription', 'label' => 'newSubscription', 'returns' => SubscriptionBuilder::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->newSubscription($b, 'default', $this->samplePriceId())],
            ['method' => 'cancelSubscription', 'label' => 'cancelSubscription', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->cancelSubscription($b, 'default')],
            ['method' => 'cancelSubscriptionNow', 'label' => 'cancelSubscriptionNow', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->cancelSubscriptionNow($b, 'default')],
            ['method' => 'resumeSubscription', 'label' => 'resumeSubscription', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->resumeSubscription($b, 'default')],
            ['method' => 'pauseSubscription', 'label' => 'pauseSubscription', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->pauseSubscription($b, 'default')],
            ['method' => 'swapSubscription', 'label' => 'swapSubscription (immediate)', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->swapSubscription($b, 'default', $this->samplePriceId(), SwapTiming::Immediate)],
            ['method' => 'swapSubscription', 'label' => 'swapSubscription (at period end)', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->swapSubscription($b, 'default', $this->samplePriceId(), SwapTiming::AtPeriodEnd)],
            ['method' => 'updateSubscriptionQuantity', 'label' => 'updateSubscriptionQuantity', 'returns' => Subscription::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->updateSubscriptionQuantity($b, 'default', 3, $this->samplePriceId())],
            ['method' => 'subscriptionLatestPayment', 'label' => 'subscriptionLatestPayment', 'returns' => Payment::class, 'nullable' => true,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->subscriptionLatestPayment($b, 'default')],
            ['method' => 'invoices', 'label' => 'invoices', 'returns' => 'array',
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->invoices($b)],
            ['method' => 'findInvoice', 'label' => 'findInvoice', 'returns' => Invoice::class, 'nullable' => true,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->findInvoice($b, $this->sampleInvoiceId())],
            ['method' => 'downloadInvoice', 'label' => 'downloadInvoice', 'returns' => Response::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->downloadInvoice($b, $this->sampleInvoiceId())],
            ['method' => 'storeInvoice', 'label' => 'storeInvoice', 'returns' => 'string',
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->storeInvoice($b, $this->sampleInvoiceId())],
            ['method' => 'paymentMethods', 'label' => 'paymentMethods', 'returns' => 'array',
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->paymentMethods($b)],
            ['method' => 'defaultPaymentMethod', 'label' => 'defaultPaymentMethod', 'returns' => PaymentMethod::class, 'nullable' => true,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->defaultPaymentMethod($b)],
            ['method' => 'addPaymentMethod', 'label' => 'addPaymentMethod', 'returns' => PaymentMethod::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->addPaymentMethod($b, $this->samplePaymentMethod())],
            ['method' => 'deletePaymentMethod', 'label' => 'deletePaymentMethod', 'void' => true,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->deletePaymentMethod($b, $this->samplePaymentMethod())],
            ['method' => 'checkout', 'label' => 'checkout (amount)', 'returns' => CheckoutSession::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->checkout($b, CheckoutRequest::forAmount(1000, new Currency('EUR')))],
            ['method' => 'checkout', 'label' => 'checkout (prices)', 'returns' => CheckoutSession::class,
                'invoke' => fn (GatewayProvider $g, Model $b) => $g->checkout($b, CheckoutRequest::forPrices($this->samplePriceId()))],
            ['method' => 'webhook', 'label' => 'webhook', 'returns' => IncomingWebhook::class,
                'invoke' => function (GatewayProvider $g, Model $b) {
                    $webhook = $this->sampleWebhook();

                    return $g->webhook($webhook['content'], $webhook['headers']);
                }],
        ];
    }

    /**
     * Assert an operation returned the type its contract declares — allowing for array, nullable
     * and void returns.
     *
     * @param  array{method: string, label: string, invoke: callable, returns?: class-string|'array'|'string', nullable?: bool, void?: bool}  $operation
     */
    private function assertOperationReturnsDeclaredType(array $operation, mixed $result): void
    {
        $label = $operation['label'];

        if ($operation['void'] ?? false) {
            $this->addToAssertionCount(1); // reaching here means it did not throw

            return;
        }

        if (($operation['returns'] ?? null) === 'array') {
            $this->assertIsArray($result, "[{$label}] must return an array.");

            return;
        }

        if (($operation['returns'] ?? null) === 'string') {
            $this->assertIsString($result, "[{$label}] must return a string.");

            return;
        }

        $expected = $operation['returns'] ?? null;
        $this->assertNotNull($expected, "[{$label}] has no declared return type in the operations() table.");

        if ($operation['nullable'] ?? false) {
            $this->assertTrue(
                $result === null || $result instanceof $expected,
                "[{$label}] must return {$expected} or null.",
            );

            return;
        }

        $this->assertInstanceOf($expected, $result, "[{$label}] must return {$expected}.");
    }
}
