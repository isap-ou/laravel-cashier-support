<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Casts;

use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * Bridges an ISO-4217 code to a moneyphp Money\Currency for spatie/laravel-data DTOs.
 *
 * Currency is the moneyphp value object across every DTO; this cast hydrates it from a
 * string on the way in and serialises it back to the ISO code on the way out. An unknown
 * code is rejected here, at the boundary, rather than surfacing later as a broken amount.
 */
class CurrencyCast implements Cast, Transformer
{
    /**
     * Hydrate an ISO-4217 code (or an existing Money\Currency) into a validated Money\Currency.
     *
     * @param  array<string, mixed>  $properties
     * @param  CreationContext<Data>  $context
     *
     * @throws InvalidArgumentException when the code is not a known ISO-4217 currency
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): ?Currency
    {
        if ($value === null || $value instanceof Currency) {
            return $value;
        }

        return self::fromCode((string) $value);
    }

    /**
     * Serialise a Money\Currency back to its ISO code for toArray()/toJson().
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): ?string
    {
        return $value instanceof Currency ? $value->getCode() : $value;
    }

    /**
     * Build a validated Money\Currency from an ISO-4217 code.
     *
     * A code no ISO-4217 currency uses is a malformed argument (a programmer error), so it
     * raises SPL's InvalidArgumentException per .claude/rules/exceptions.md — not a typed
     * CashierException an app is invited to catch, and not moneyphp's DomainException, which
     * belongs to neither side of the package's exception boundary.
     *
     * @throws InvalidArgumentException when the code is not a known ISO-4217 currency
     */
    public static function fromCode(string $code): Currency
    {
        if ($code === '') {
            throw new InvalidArgumentException('A currency code is required.');
        }

        $currency = new Currency(strtoupper($code));

        if (! (new ISOCurrencies)->contains($currency)) {
            throw new InvalidArgumentException("Unknown ISO 4217 currency [{$currency->getCode()}].");
        }

        return $currency;
    }
}
