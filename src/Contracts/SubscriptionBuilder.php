<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use DateTimeInterface;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;

/**
 * Fluent builder for creating a new subscription.
 *
 * Mirrors the builder returned by laravel/cashier-stripe's newSubscription().
 */
interface SubscriptionBuilder
{
    /**
     * Set the number of trial days before the subscription is billed.
     */
    public function trialDays(int $days): static;

    /**
     * Set the date the trial should end.
     */
    public function trialUntil(DateTimeInterface $date): static;

    /**
     * Set the quantity of the subscription.
     */
    public function quantity(int $quantity): static;

    /**
     * Attach arbitrary metadata to the subscription.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static;

    /**
     * Create the subscription.
     *
     * @param  string|null  $paymentMethod  The payment method identifier.
     * @param  array<string, mixed>  $options
     *
     * @throws PaymentFailedException When the initial payment is declined.
     */
    public function create(?string $paymentMethod = null, array $options = []): Subscription;

    /**
     * Add the subscription without collecting payment upfront.
     *
     * @param  array<string, mixed>  $options
     */
    public function add(array $options = []): Subscription;
}
