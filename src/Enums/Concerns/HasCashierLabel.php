<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Enums\Concerns;

use IsapOu\EnumHelpers\Concerns\HasLabel;

/**
 * Wires isap-ou/laravel-enum-helpers label translation to this package's
 * own translation namespace, independent of the host application's
 * enum-helpers configuration.
 *
 * Resolves labels from the key: cashier-support::enums.{ShortClassName}.{CaseName}
 */
trait HasCashierLabel
{
    use HasLabel;

    protected function getPrefix(): ?string
    {
        return 'enums';
    }

    protected function getNamespace(): ?string
    {
        return 'cashier-support';
    }
}
