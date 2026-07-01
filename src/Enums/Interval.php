<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * Billing interval for recurring subscriptions.
 */
enum Interval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
