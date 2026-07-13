<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * Why an invoice was raised.
 *
 * Makes a renewal identifiable at the record level, not only at the moment its
 * event fires — so a billing history can be reconstructed from the database.
 */
enum BillingReason: string
{
    /** The first charge of a subscription. */
    case SubscriptionCreate = 'subscription_create';

    /** A recurring charge for a billing cycle — a renewal. */
    case SubscriptionCycle = 'subscription_cycle';

    /** A one-off charge that no subscription drives. */
    case Manual = 'manual';
}
