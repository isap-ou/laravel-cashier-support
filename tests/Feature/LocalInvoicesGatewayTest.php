<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Isapp\CashierSupport\Contracts\InvoiceRenderer;
use Isapp\CashierSupport\Contracts\RendersInvoices;
use Isapp\CashierSupport\DTO\Invoice;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Gateway\ManagesLocalInvoices;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;
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

    /**
     * A gateway that DOES render — it implements RendersInvoices and hands back a fake
     * renderer that echoes the invoice id, so download/store can be asserted on bytes.
     */
    private function renderingGateway(): object
    {
        return new class implements RendersInvoices
        {
            use ManagesLocalInvoices;

            protected function driverName(): string
            {
                return 'fake';
            }

            public function invoiceRenderer(): InvoiceRenderer
            {
                return new class implements InvoiceRenderer
                {
                    public function render(Invoice $invoice, array $data = []): string
                    {
                        return '%PDF '.$invoice->id;
                    }
                };
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
            'currency' => new Currency('EUR'),
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

        // A gateway that CAN render still 404s on a foreign invoice — ownership is checked
        // after the render gate passes.
        $gateway = $this->renderingGateway();

        $this->assertNull($gateway->findInvoice($ada, 'ord_bob'));

        $this->expectException(NotFoundHttpException::class);
        $gateway->downloadInvoice($ada, 'ord_bob');
    }

    public function test_the_filename_falls_back_to_the_record_key_without_a_number(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $record = $this->invoiceFor($ada, 'ord_nonum'); // no 'number' attribute

        $response = $this->renderingGateway()->downloadInvoice($ada, 'ord_nonum');

        $this->assertStringContainsString(
            'invoice-'.$record->getKey().'.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_a_gateway_that_cannot_render_refuses_even_a_missing_invoice(): void
    {
        // The refusal is unconditional: the render gate fires before the record lookup, so a
        // missing id yields UnsupportedOperationException, not a 404 that masks the misconfig.
        $ada = User::query()->create(['name' => 'Ada']);

        $this->expectException(UnsupportedOperationException::class);
        $this->gatewayInvoices()->downloadInvoice($ada, 'nope');
    }

    public function test_it_downloads_a_rendered_invoice(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_dl', ['number' => 'INV-7']);

        $response = $this->renderingGateway()->downloadInvoice($ada, 'ord_dl');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('invoice-INV-7.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF ord_dl', $response->getContent());
    }

    public function test_it_stores_a_rendered_invoice_and_returns_the_path(): void
    {
        Storage::fake();

        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_store', ['number' => 'INV-9']);

        $path = $this->renderingGateway()->storeInvoice($ada, 'ord_store');

        $this->assertSame('invoices/invoice-INV-9.pdf', $path);
        Storage::assertExists($path);
        $this->assertSame('%PDF ord_store', Storage::get($path));
    }

    public function test_store_honours_a_custom_disk_and_path(): void
    {
        Storage::fake('invoices-disk');

        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_custom');

        $path = $this->renderingGateway()->storeInvoice($ada, 'ord_custom', [], 'invoices-disk', 'custom/inv.pdf');

        $this->assertSame('custom/inv.pdf', $path);
        Storage::disk('invoices-disk')->assertExists('custom/inv.pdf');
        $this->assertSame('%PDF ord_custom', Storage::disk('invoices-disk')->get('custom/inv.pdf'));
    }

    public function test_download_refuses_when_the_gateway_does_not_render(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_norender');

        $this->expectException(UnsupportedOperationException::class);
        $this->gatewayInvoices()->downloadInvoice($ada, 'ord_norender');
    }

    public function test_store_refuses_when_the_gateway_does_not_render(): void
    {
        $ada = User::query()->create(['name' => 'Ada']);
        $this->invoiceFor($ada, 'ord_norender');

        $this->expectException(UnsupportedOperationException::class);
        $this->gatewayInvoices()->storeInvoice($ada, 'ord_norender');
    }
}
