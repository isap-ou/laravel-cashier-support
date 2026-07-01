<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Isapp\CashierSupport\Contracts\PaymentMethodType;
use Spatie\LaravelData\Data;

/**
 * A stored payment method.
 *
 * The type is provider-defined: it is any enum implementing the
 * PaymentMethodType contract, so this DTO stays provider-agnostic.
 */
class PaymentMethod extends Data
{
    public function __construct(
        public string $id,
        public PaymentMethodType $type,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expMonth = null,
        public ?int $expYear = null,
    ) {}
}
