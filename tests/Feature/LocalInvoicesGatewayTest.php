<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Isapp\CashierSupport\Enums\Currency;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\ManagesLocalInvoices;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LocalInvoicesGatewayTest extends TestCase
{
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

        Cashier::useModels('fake', ['invoice' => ConcreteInvoice::class]);
    }

    private function gatewayInvoices(): object
    {
        return new class
        {
            use ManagesLocalInvoices;

            protected function driverName(): string
            {
                return 'fake';
            }
        };
    }

    private function invoiceFor(Model $owner, string $providerId, array $overrides = []): ConcreteInvoice
    {
        return ConcreteInvoice::query()->create(array_merge([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'provider' => 'fake',
            'provider_id' => $providerId,
            'amount' => 1500,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
            'issued_at' => now(),
        ], $overrides));
    }

    public function test_invoices_are_scoped_to_the_owner_and_driver(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $bob = User::query()->create(['name' => 'Bob']);

        $this->invoiceFor($ada, 'ord_ada');
        $this->invoiceFor($bob, 'ord_bob');
        $this->invoiceFor($ada, 'ord_foreign', ['provider' => 'other-driver']);

        $invoices = $this->gatewayInvoices()->invoices($ada);

        $this->assertCount(1, $invoices);
        $this->assertSame('ord_ada', $invoices[0]->id);
        $this->assertSame(1500, $invoices[0]->amount);
    }

    public function test_invoices_honors_the_limit_parameter(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_1');
        $this->invoiceFor($ada, 'ord_2');

        $this->assertCount(1, $this->gatewayInvoices()->invoices($ada, ['limit' => 1]));
    }

    public function test_find_invoice_matches_provider_id_and_uuid_key(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $record = $this->invoiceFor($ada, 'ord_1');

        $gateway = $this->gatewayInvoices();

        $this->assertNotNull($gateway->findInvoice($ada, 'ord_1'));
        $this->assertNotNull($gateway->findInvoice($ada, (string) $record->getKey()));
        $this->assertNull($gateway->findInvoice($ada, 'ord_unknown'));
    }

    public function test_it_hydrates_persisted_lines_and_the_tax_breakdown(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_vat', [
            'amount' => 1700,
            'subtotal' => 1500,
            'tax' => 300,
            'discount' => 100,
            'lines' => [
                ['description' => 'Pro plan', 'amount' => 1000, 'quantity' => 1, 'unitAmount' => 1000, 'taxAmount' => 200, 'taxRate' => 2000],
                ['description' => 'Add-on', 'amount' => 500, 'quantity' => 2, 'unitAmount' => 250, 'taxAmount' => 100, 'taxRate' => 2000],
            ],
        ]);

        $invoice = $this->gatewayInvoices()->findInvoice($ada, 'ord_vat');

        $this->assertNotNull($invoice);
        $this->assertSame(1500, $invoice->subtotal);
        $this->assertSame(300, $invoice->tax);
        $this->assertSame(100, $invoice->discount);

        $this->assertCount(2, $invoice->lines);
        $this->assertSame('Pro plan', $invoice->lines[0]->description);
        $this->assertSame(1000, $invoice->lines[0]->unitAmount);
        $this->assertSame(200, $invoice->lines[0]->taxAmount);
        $this->assertSame(2000, $invoice->lines[0]->taxRate);
    }

    public function test_legacy_rows_without_lines_hydrate_to_an_empty_breakdown(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_legacy');

        $invoice = $this->gatewayInvoices()->findInvoice($ada, 'ord_legacy');

        $this->assertNotNull($invoice);
        $this->assertSame([], $invoice->lines);
        $this->assertNull($invoice->subtotal);
        $this->assertNull($invoice->tax);
        $this->assertNull($invoice->discount);
    }

    public function test_a_user_cannot_access_another_users_invoice(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $bob = User::query()->create(['name' => 'Bob']);
        $this->invoiceFor($bob, 'ord_bob');

        $gateway = $this->gatewayInvoices();

        $this->assertNull($gateway->findInvoice($ada, 'ord_bob'));

        $this->expectException(NotFoundHttpException::class);
        $gateway->downloadInvoice($ada, 'ord_bob');
    }
}
