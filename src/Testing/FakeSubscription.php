<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Testing;

use Isapp\CashierSupport\Models\Subscription as BaseSubscription;

/**
 * The concrete Subscription model Cashier::fake() binds for the fake driver.
 *
 * Models\Subscription is abstract — deliberately, so each driver names its own — which
 * left Cashier::fake() unable to keep the promise in its docblock: an app could fake a
 * charge, but $user->subscribed() threw InvalidConfigurationException because no model
 * was registered for the [fake] driver, and there was no concrete class to register.
 * Advising the app to "call Cashier::useModels('fake', …) in its service provider" was
 * doubly unhelpful — the fake has no service provider, and with no driver installed
 * there was nothing to point at.
 *
 * It adds no behaviour. Its whole job is to exist on the production autoloader so the
 * fake has something to bind, exactly as a driver's own model does.
 */
class FakeSubscription extends BaseSubscription {}
