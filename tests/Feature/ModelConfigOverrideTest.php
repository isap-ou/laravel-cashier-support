<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Foundation\Application;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Models\Customer;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteCustomer;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteInvoice;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscription;
use Isapp\CashierSupport\Tests\Fixtures\ConcreteSubscriptionItem;
use Isapp\CashierSupport\Tests\TestCase;

/**
 * Stands in for a model a driver registers from its own service provider,
 * exactly as cashier-revolut's does. Distinct from ConcreteCustomer so the two
 * sources can be told apart when both name a class.
 */
class DriverRegisteredCustomer extends Customer {}

/**
 * Covers the app-level model override: the published cashier-support.models.*
 * config, and how CashierManager::model() weighs it against the driver's
 * registry. No test supplied a value through the config before this file.
 *
 * Three separate things have to be true for publishing the config to mean
 * anything, and each failed independently:
 *
 * 1. The array must NAME the slot. Asserted against the file, not the
 *    container — config()->set() creates the key whether or not the shipped
 *    stub declares it, so a test that only overrides at runtime stays green
 *    while an app that publishes the config finds no customer line to edit.
 * 2. The config must OUTRANK the driver's registry. A driver registers every
 *    slot from its service provider, so a registry-first lookup made the config
 *    reachable only when no driver had registered anything — i.e. never, in any
 *    install that has a driver.
 * 3. A config value that is named but unusable must FAIL, not fall through to
 *    the driver. An override that loses silently is the same defect as one that
 *    is never read.
 */
class ModelConfigOverrideTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-support.default', 'fake');
    }

    /**
     * The published config, as an app receives it — read from the file rather
     * than the container, which merges and mutates it.
     *
     * @return array<string, mixed>
     */
    private function publishedConfig(): array
    {
        return require dirname(__DIR__, 2).'/config/cashier-support.php';
    }

    public function test_the_published_config_declares_every_slot_the_manager_resolves(): void
    {
        $models = $this->publishedConfig()['models'];

        // One line per Cashier::*Model() accessor. A slot the manager reads but
        // the config never names can only be overridden from a service provider,
        // which is not what publishing the config promises.
        $this->assertSame(
            ['customer', 'subscription', 'subscription_item', 'invoice'],
            array_keys($models),
            'The published models array must name every slot CashierManager::model() resolves.',
        );
    }

    public function test_every_published_slot_defaults_to_null(): void
    {
        foreach ($this->publishedConfig()['models'] as $slot => $default) {
            // Not merely "unset by default": a slot may not default to its own
            // abstract class either. model() gates the override behind
            // is_subclass_of(), which is false when class === abstract, so an
            // abstract default would make a stock install throw on first use.
            $this->assertNull($default, "The [{$slot}] slot must default to null.");
        }
    }

    public function test_the_published_config_outranks_the_driver_registry(): void
    {
        // The shape every real install has, and the one this test file missed
        // when it was first written: a driver registers its models from its own
        // service provider (cashier-revolut does, for all four slots), and the
        // app publishes the config to point a slot somewhere else. If the
        // registry wins, publishing the config does nothing at all and the
        // models array is decoration.
        Cashier::useModels('fake', ['customer' => DriverRegisteredCustomer::class]);

        config(['cashier-support.models.customer' => ConcreteCustomer::class]);

        $this->assertSame(ConcreteCustomer::class, Cashier::customerModel());
    }

    public function test_the_driver_registry_still_answers_when_the_config_names_nothing(): void
    {
        // The published default is null for every slot, so an app that changes
        // nothing must still get the driver's own models.
        Cashier::useModels('fake', ['customer' => DriverRegisteredCustomer::class]);

        $this->assertSame(DriverRegisteredCustomer::class, Cashier::customerModel());
    }

    public function test_a_config_slot_pointed_at_a_bad_class_is_not_masked_by_the_registry(): void
    {
        // The app's override losing silently to the driver's model is the same
        // defect as the override being ignored: either way the published value
        // goes nowhere. A misconfigured slot must say so, not fall through.
        Cashier::useModels('fake', ['customer' => DriverRegisteredCustomer::class]);

        config(['cashier-support.models.customer' => Customer::class]);

        $this->expectException(InvalidConfigurationException::class);
        // Names the app's config, not the driver: the driver registered a
        // perfectly good model here and is not what failed.
        $this->expectExceptionMessage('[cashier-support.models.customer] config names');

        Cashier::customerModel();
    }

    public function test_a_misspelled_config_class_is_not_blamed_on_its_parent(): void
    {
        // The likeliest mistake in a hand-edited config, and is_subclass_of()
        // reports it identically to a wrong parent. Saying "does not extend"
        // here would send the reader to audit an inheritance chain that does
        // not exist.
        config(['cashier-support.models.customer' => 'App\Modles\Customer']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('which does not exist');

        Cashier::customerModel();
    }

    public function test_has_model_does_not_mask_a_misconfigured_slot(): void
    {
        Cashier::useModels('fake', ['customer' => DriverRegisteredCustomer::class]);

        config(['cashier-support.models.customer' => Customer::class]);

        // hasModel() answers "did anyone name a model", not "is it any good".
        // Answering false here would let a read path report a silent "no
        // record" for what is really the app's typo — the same silence the
        // config-first order exists to end.
        $this->assertTrue(Cashier::hasModel('customer'));

        $this->expectException(InvalidConfigurationException::class);

        Cashier::customerModel();
    }

    public function test_a_slot_nobody_names_blames_nobody_in_particular(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('driver has not registered a [customer] model');

        Cashier::customerModel();
    }

    public function test_each_slot_can_be_overridden_through_config(): void
    {
        config([
            'cashier-support.models.customer' => ConcreteCustomer::class,
            'cashier-support.models.subscription' => ConcreteSubscription::class,
            'cashier-support.models.subscription_item' => ConcreteSubscriptionItem::class,
            'cashier-support.models.invoice' => ConcreteInvoice::class,
        ]);

        $this->assertSame(ConcreteCustomer::class, Cashier::customerModel());
        $this->assertSame(ConcreteSubscription::class, Cashier::subscriptionModel());
        $this->assertSame(ConcreteSubscriptionItem::class, Cashier::subscriptionItemModel());
        $this->assertSame(ConcreteInvoice::class, Cashier::invoiceModel());
    }

    public function test_a_slot_pointed_at_the_abstract_model_is_rejected(): void
    {
        config(['cashier-support.models.customer' => Customer::class]);

        $this->expectException(InvalidConfigurationException::class);

        Cashier::customerModel();
    }

    public function test_has_model_reads_the_config_slot_too(): void
    {
        $this->assertFalse(Cashier::hasModel('customer'));

        config(['cashier-support.models.customer' => ConcreteCustomer::class]);

        $this->assertTrue(Cashier::hasModel('customer'));
    }

    public function test_the_config_override_does_not_bleed_into_a_non_default_driver(): void
    {
        config(['cashier-support.models.customer' => ConcreteCustomer::class]);

        // The published config is the app's override for the driver it named as
        // default. Another driver's models are that driver's own business.
        $this->expectException(InvalidConfigurationException::class);

        Cashier::customerModel('other');
    }

    public function test_a_non_default_driver_is_not_told_to_edit_a_config_it_never_reads(): void
    {
        try {
            Cashier::customerModel('other');
            $this->fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            $first = $e->getMessage();
        }

        // Advice that cannot work is worse than no advice: it costs the reader
        // the time to try it. The config arm only runs for the default driver,
        // so for any other one the only true remedy is useModels().
        $this->assertStringNotContainsString('cashier-support.models.customer', $first);

        // And prove the advice would have been false — do exactly what a
        // config-mentioning message would have told this caller to do.
        config(['cashier-support.models.customer' => ConcreteCustomer::class]);

        try {
            Cashier::customerModel('other');
            $this->fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame($first, $e->getMessage(), 'Setting the config changed nothing for a non-default driver, as designed.');
        }
    }

    public function test_the_default_driver_is_told_about_both_remedies(): void
    {
        try {
            Cashier::customerModel();
            $this->fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            // Here the config IS consulted, so naming it is actionable.
            $this->assertStringContainsString("useModels('fake'", $e->getMessage());
            $this->assertStringContainsString('cashier-support.models.customer', $e->getMessage());
        }
    }

    public function test_a_bad_class_is_blamed_on_the_registry_when_the_config_never_supplied_it(): void
    {
        // The config names the same class the driver registered, but for a
        // NON-default driver the config arm never ran — so the value came from
        // the registry, and blaming the config would be an accusation against
        // a line the manager never read.
        Cashier::useModels('other', ['customer' => Customer::class]);

        config(['cashier-support.models.customer' => Customer::class]);

        try {
            Cashier::customerModel('other');
            $this->fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringNotContainsString('config names', $e->getMessage());
        }
    }
}
