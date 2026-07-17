<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Money\Currency;

/**
 * Eloquent cast for the money currency column: string code <-> Money\Currency.
 *
 * Mirrors CurrencyCast (the DTO side) so a stored ISO code round-trips to the same value
 * object, validated in both directions.
 *
 * @implements CastsAttributes<Currency, Currency|string>
 */
class CurrencyEloquentCast implements CastsAttributes
{
    /**
     * Hydrate the stored ISO code into a Money\Currency.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException when the stored code is not a known ISO-4217 currency
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Currency
    {
        return $value === null ? null : CurrencyCast::fromCode((string) $value);
    }

    /**
     * Store a Money\Currency (or an ISO code) as the validated ISO code string.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \InvalidArgumentException when the code is not a known ISO-4217 currency
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return CurrencyCast::fromCode($value instanceof Currency ? $value->getCode() : (string) $value)->getCode();
    }
}
