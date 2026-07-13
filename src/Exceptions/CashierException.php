<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

use Exception;

/**
 * Base exception for every BILLING failure — in this package and in its drivers.
 *
 * The boundary is the one Stripe and Paddle Cashier draw, and it is not arbitrary:
 *
 *  - A billing failure is a fact about the world. The card was declined, the
 *    gateway cannot pause a subscription, the customer does not exist, the API is
 *    down. The app cannot prevent it, so it must be able to catch it — and
 *    `catch (CashierException)` around a gateway call catches all of it.
 *  - A malformed argument is a programmer error: swapping to no price at all,
 *    checking out a negative amount. It is raised as SPL's InvalidArgumentException
 *    and is meant to be fixed, not caught. The reference does the same — see
 *    laravel/cashier's Subscription::swap(): "Please provide at least one price
 *    when swapping."
 *
 * A driver that raises a bare exception for a *billing* failure breaks the
 * hierarchy and is a defect. One that raises InvalidArgumentException for a
 * malformed argument is following this contract.
 */
class CashierException extends Exception {}
