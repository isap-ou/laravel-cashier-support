<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use InvalidArgumentException;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\CheckoutMode;
use Isapp\CashierSupport\Enums\Currency;
use Spatie\LaravelData\Data;

/**
 * What to check out.
 *
 * Two shapes, both legitimate, and a gateway supports one or the other:
 *
 *  - by catalogue price   — N price ids × quantities (Stripe, Paddle)
 *  - by amount            — an amount, a currency, a description (Revolut, Adyen)
 *
 * The contract used to type only the first, so the gateways that take an amount
 * had to smuggle it through an untyped options bag and throw when it was absent.
 */
class CheckoutRequest extends Data
{
    /**
     * @param  array<string, int>  $items  Price identifiers mapped to quantities.
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $options  Provider-specific escape hatch, named as such.
     */
    public function __construct(
        public array $items = [],
        public ?int $amount = null,
        public ?Currency $currency = null,
        public ?string $description = null,
        public ?string $successUrl = null,
        public ?string $cancelUrl = null,
        public CheckoutMode $mode = CheckoutMode::Payment,
        public array $metadata = [],
        public array $options = [],
    ) {}

    /**
     * Check out a catalogue of prices.
     *
     * @param  array<string, int>|string  $items  Price ids mapped to quantities, or one price id.
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $options
     */
    public static function forPrices(
        array|string $items,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
        CheckoutMode $mode = CheckoutMode::Payment,
        array $metadata = [],
        array $options = [],
    ): self {
        $items = is_string($items) ? [$items => 1] : $items;

        if ($items === []) {
            throw new InvalidArgumentException('Please provide at least one price when checking out.');
        }

        return new self(
            items: $items,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            mode: $mode,
            metadata: $metadata,
            options: $options,
        );
    }

    /**
     * Check out an ad-hoc amount.
     *
     * @param  int  $amount  In minor units (cents).
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $options
     */
    public static function forAmount(
        int $amount,
        Currency $currency,
        ?string $description = null,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
        CheckoutMode $mode = CheckoutMode::Payment,
        array $metadata = [],
        array $options = [],
    ): self {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A checkout amount must be positive in minor units; got [{$amount}].");
        }

        return new self(
            amount: $amount,
            currency: $currency,
            description: $description,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            mode: $mode,
            metadata: $metadata,
            options: $options,
        );
    }

    public function isPrices(): bool
    {
        return $this->items !== [];
    }

    public function isAmount(): bool
    {
        return $this->amount !== null;
    }

    /**
     * The capability a gateway must declare to honour this request's shape.
     *
     * This is also where a request built through the inherited Data entry points
     * (::from(), request injection) is checked — those bypass the named
     * constructors, so a request that is neither shape, or both, or carries a
     * non-positive amount, must not reach a driver.
     *
     * @throws InvalidArgumentException When the request is not exactly one shape.
     */
    public function capability(): Capability
    {
        if ($this->isAmount() === $this->isPrices()) {
            throw new InvalidArgumentException(
                'A checkout request must carry either prices or an amount, not both and not neither.',
            );
        }

        if ($this->isAmount() && (int) $this->amount <= 0) {
            throw new InvalidArgumentException(
                "A checkout amount must be positive in minor units; got [{$this->amount}].",
            );
        }

        return $this->isAmount() ? Capability::CheckoutAmount : Capability::CheckoutPrices;
    }
}
