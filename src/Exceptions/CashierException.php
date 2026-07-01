<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Exceptions;

use Exception;

/**
 * Base exception for all cashier-support errors.
 *
 * Every exception thrown by this package and its concrete providers
 * extends this class, so applications can catch the whole hierarchy.
 */
class CashierException extends Exception {}
