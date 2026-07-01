<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums;

/**
 * ISO 4217 currency codes supported by the package.
 */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case PLN = 'PLN';
    case CZK = 'CZK';
    case CHF = 'CHF';
    case SEK = 'SEK';
    case NOK = 'NOK';
    case DKK = 'DKK';
    case RON = 'RON';
    case HUF = 'HUF';
    case BGN = 'BGN';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case JPY = 'JPY';

    /**
     * Number of minor units (decimal places) for the currency.
     *
     * Used to interpret the integer (cents) amounts handled by the package.
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::JPY => 0,
            default => 2,
        };
    }
}
