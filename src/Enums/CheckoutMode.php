<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * Mode of a hosted checkout session.
 */
enum CheckoutMode: string
{
    case Payment = 'payment';
    case Subscription = 'subscription';
    case Setup = 'setup';
}
