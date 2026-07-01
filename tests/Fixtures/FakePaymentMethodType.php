<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Isapp\CashierSupport\Contracts\PaymentMethodType;
use Isapp\CashierSupport\Enums\Concerns\HasCashierLabel;

/**
 * A provider-style payment method type enum for tests.
 */
enum FakePaymentMethodType: string implements PaymentMethodType
{
    use HasCashierLabel;

    case Card = 'card';
    case RevolutPay = 'revolut_pay';
}
