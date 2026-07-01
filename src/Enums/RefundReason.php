<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

use IsapOu\EnumHelpers\Concerns\InteractWithCollection;
use IsapOu\EnumHelpers\Contracts\HasLabel;
use Isapp\CashierSupport\Enums\Concerns\HasCashierLabel;

/**
 * Reason a refund was issued.
 */
enum RefundReason: string implements HasLabel
{
    use HasCashierLabel;
    use InteractWithCollection;

    case Duplicate = 'duplicate';
    case Fraudulent = 'fraudulent';
    case RequestedByCustomer = 'requested_by_customer';
    case Other = 'other';
}
