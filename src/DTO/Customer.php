<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Spatie\LaravelData\Data;

/**
 * A billing customer at the gateway provider.
 */
class Customer extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $email = null,
        public ?array $metadata = null,
    ) {}
}
