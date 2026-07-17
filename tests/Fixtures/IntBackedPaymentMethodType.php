<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Isapp\CashierSupport\Contracts\PaymentMethodType;
use Isapp\CashierSupport\Enums\Concerns\HasCashierLabel;

/**
 * A driver enum that ignores the string-backed requirement on Contracts\PaymentMethodType.
 *
 * It exists because the language allows it: `BackedEnum` permits `int`, so the interface
 * cannot forbid this and PHP will load a driver that ships it. The requirement is therefore
 * stated in a docblock, and a docblock is not enforcement — this fixture is.
 *
 * What it protects is a silent failure, not a crash: `1 === '1'` is false, so a naive
 * comparison of the caller's string against `->value` would make hasPaymentMethod() answer a
 * confident "no" about a method that is right there, and deletePaymentMethods() delete
 * nothing while reporting success.
 */
enum IntBackedPaymentMethodType: int implements PaymentMethodType
{
    use HasCashierLabel;

    case Card = 1;
}
