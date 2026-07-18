<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use InvalidArgumentException;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\CheckoutMode;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;

/**
 * A capability must gate an intent the caller expresses, not merely announce
 * that an operation exists.
 *
 * "Supports swap" was true for a gateway that swaps immediately and for one that
 * defers to the end of the billing cycle — semantics an app cannot ignore, and
 * could not ask about, so it branched on the driver name instead. Same for
 * checkout: one gateway takes price ids, another takes an amount, and the
 * contract claimed only the first existed.
 */
class GranularCapabilitiesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    /**
     * @param  array<int, Capability>  $capabilities
     */
    private function driverSupporting(array $capabilities): void
    {
        Cashier::extend('fake', fn () => new FakeGateway([Capability::Subscriptions, ...$capabilities]));
    }

    // Subscription swap/pause timing gating moved to Models\Subscription with #39 and is proven
    // through the model API in Tests\Feature\SubscriptionMutationTest (and on the guard itself in
    // GuardedProviderTest). What stays here is the other half of "gate the intent": checkout,
    // whose shape (prices vs amount) is the intent, and which is still a Billable call.

    public function test_a_price_checkout_on_an_amount_only_gateway_throws_in_support(): void
    {
        // The guard belongs here, not in the driver: a mis-shaped request is
        // caught before it reaches one, so no driver has to invent its own
        // exception outside the Cashier hierarchy.
        $this->driverSupporting([Capability::CheckoutAmount]);

        $this->expectException(UnsupportedOperationException::class);
        (new User)->checkout(CheckoutRequest::forPrices(['price_1' => 1]));
    }

    public function test_an_amount_checkout_on_an_amount_gateway_works(): void
    {
        $this->driverSupporting([Capability::CheckoutAmount]);

        $session = (new User)->checkout(
            CheckoutRequest::forAmount(1500, new Currency('EUR'), 'A thing'),
        );

        $this->assertSame('cs_fake', $session->id());
        $this->assertSame('secret_fake', $session->clientSecret());
    }

    public function test_a_price_checkout_on_a_price_gateway_works(): void
    {
        $this->driverSupporting([Capability::CheckoutPrices]);

        $session = (new User)->checkout(CheckoutRequest::forPrices(['price_1' => 2]));

        $this->assertSame('cs_fake', $session->id());
    }

    public function test_legacy_checkout_arguments_still_work(): void
    {
        // App code that predates the request object keeps working: a bare price
        // id or an items map normalizes to a price-shaped request.
        $this->driverSupporting([Capability::CheckoutPrices]);

        $this->assertSame('cs_fake', (new User)->checkout('price_1')->id());
        $this->assertSame('cs_fake', (new User)->checkout(['price_1' => 2])->id());
    }

    public function test_legacy_urls_and_mode_are_lifted_out_of_the_options_bag(): void
    {
        // They used to travel in the bag, and each driver fished them out under
        // whatever key it read. They are typed fields now — a legacy call must
        // not hand the driver a request whose successUrl is null while the url
        // sits unread in options.
        $gateway = new FakeGateway([Capability::Subscriptions, Capability::CheckoutPrices]);
        Cashier::extend('fake', fn () => $gateway);

        (new User)->checkout('price_1', [
            'success_url' => 'https://app.test/ok',
            'cancel_url' => 'https://app.test/no',
            'mode' => 'subscription',
            'locale' => 'nl',
        ]);

        $request = $gateway->lastCheckoutRequest;

        $this->assertNotNull($request);
        $this->assertSame('https://app.test/ok', $request->successUrl);
        $this->assertSame('https://app.test/no', $request->cancelUrl);
        $this->assertSame(CheckoutMode::Subscription, $request->mode);
        $this->assertSame(['locale' => 'nl'], $request->options);
    }

    public function test_a_misspelled_legacy_mode_is_refused_not_silently_charged_once(): void
    {
        $this->driverSupporting([Capability::CheckoutPrices]);

        $this->expectException(InvalidArgumentException::class);
        (new User)->checkout('price_1', ['mode' => 'subscribtion']);
    }

    public function test_a_non_positive_amount_never_reaches_a_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutRequest::forAmount(0, new Currency('EUR'));
    }

    public function test_an_empty_catalogue_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutRequest::forPrices([]);
    }

    public function test_a_request_built_through_the_data_entry_points_is_still_validated(): void
    {
        // ::from() skips the named constructors — spatie/laravel-data offers it
        // on every Data subclass and an app will use it. A request that is
        // neither shape, or carries an amount of zero, must not reach a driver
        // as a "price checkout with an empty catalogue".
        $neitherShape = CheckoutRequest::from(['items' => [], 'amount' => null]);

        $this->expectException(InvalidArgumentException::class);
        $neitherShape->capability();
    }

    public function test_a_zero_amount_from_the_data_entry_points_is_refused_too(): void
    {
        $zero = CheckoutRequest::from(['amount' => 0, 'currency' => 'EUR']);

        $this->expectException(InvalidArgumentException::class);
        $zero->capability();
    }

    public function test_an_amount_without_a_currency_is_refused_rather_than_charged_in_the_default(): void
    {
        // A default here would charge 15.00 EUR for an intended 15.00 GBP and
        // the app would never learn it happened.
        $request = CheckoutRequest::from(['amount' => 1500]);

        $this->expectException(InvalidArgumentException::class);
        $request->capability();
    }

    public function test_a_zero_quantity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutRequest::forPrices(['price_1' => 0]);
    }

    public function test_options_alongside_a_request_are_refused_rather_than_dropped(): void
    {
        // The request names every field the bag used to carry, so options next
        // to it can only be a mistake — and silently dropping a success_url the
        // caller believes they passed is the worst way to answer it.
        $this->driverSupporting([Capability::CheckoutPrices]);

        $this->expectException(InvalidArgumentException::class);
        (new User)->checkout(
            CheckoutRequest::forPrices(['price_1' => 1]),
            ['success_url' => 'https://app.test/ok'],
        );
    }

    public function test_a_request_carries_its_urls_and_mode_as_first_class_fields(): void
    {
        $request = CheckoutRequest::forAmount(
            amount: 1500,
            currency: new Currency('EUR'),
            description: 'A thing',
            successUrl: 'https://app.test/ok',
            cancelUrl: 'https://app.test/no',
            mode: CheckoutMode::Payment,
        );

        $this->assertSame(1500, $request->amount);
        $this->assertSame('EUR', $request->currency->getCode());
        $this->assertSame('https://app.test/ok', $request->successUrl);
        $this->assertSame(CheckoutMode::Payment, $request->mode);
        $this->assertSame([], $request->items);
        $this->assertTrue($request->isAmount());
        $this->assertFalse($request->isPrices());
    }
}
