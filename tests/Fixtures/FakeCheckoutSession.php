<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\Enums\CheckoutMode;

class FakeCheckoutSession implements CheckoutSession
{
    public function __construct(
        private readonly string $id,
        private readonly CheckoutMode $mode = CheckoutMode::Payment,
        private readonly ?string $url = 'https://pay.example.com/session',
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function mode(): CheckoutMode
    {
        return $this->mode;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function clientSecret(): ?string
    {
        return 'secret_fake';
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return null;
    }
}
