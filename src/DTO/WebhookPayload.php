<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\WebhookEvent;
use Spatie\LaravelData\Data;

/**
 * A normalized, provider-agnostic webhook payload.
 *
 * Concrete providers translate their native webhook body into this shape.
 */
class WebhookPayload extends Data
{
    /**
     * @param  array<string, mixed>  $data  Provider-agnostic event data.
     */
    public function __construct(
        public WebhookEvent $event,
        public string $id,
        public array $data = [],
        public ?CarbonImmutable $createdAt = null,
    ) {}
}
