<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when a subscription update (swap, cancel, pause, resume) fails.
 *
 * The failure is about the subscription's STATE — there is no such subscription,
 * the gateway refused the change. A malformed argument (no price, more prices than
 * the gateway bills on) is a programmer error and raises InvalidArgumentException;
 * dressing it up as an update failure invites an app to catch its own bug.
 */
class SubscriptionUpdateFailure extends CashierException {}
