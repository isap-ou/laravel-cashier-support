<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

/**
 * Thrown when changing a subscription fails — swapping, cancelling, pausing, resuming, or
 * restating its quantity.
 *
 * **The rule, not the list, is what decides:** the failure is about the subscription's STATE.
 * There is no such subscription, the gateway refused the change, the quantity billed today is
 * unknown so there is nothing to raise or lower. A malformed argument (no price, more prices
 * than the gateway bills on, a count below one) is a programmer error and raises
 * InvalidArgumentException; dressing it up as an update failure invites an app to catch its own
 * bug. Quantity was added to the list by #37 — if the next operation fits the rule, add it too
 * rather than reading the enumeration as closed.
 */
class SubscriptionUpdateFailure extends CashierException {}
