<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

use IsapOu\EnumHelpers\Concerns\InteractWithCollection;
use IsapOu\EnumHelpers\Contracts\HasLabel;
use Isapp\CashierSupport\Enums\Concerns\HasCashierLabel;

/**
 * Lifecycle status of a payment / charge.
 */
enum PaymentStatus: string implements HasLabel
{
    use HasCashierLabel;
    use InteractWithCollection;

    case Pending = 'pending';
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';

    /**
     * Whether the payment reached a successful terminal state.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }
}
