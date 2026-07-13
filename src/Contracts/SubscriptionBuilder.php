<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use DateTimeInterface;
use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\PaymentFailedException;
use Isapp\CashierSupport\Exceptions\UnsupportedOperationException;

/**
 * Fluent builder for creating a new subscription.
 *
 * Mirrors the builder returned by laravel/cashier-stripe's newSubscription().
 */
interface SubscriptionBuilder
{
    /**
     * Set the number of trial days before the subscription is billed.
     *
     * @throws UnsupportedOperationException When the provider does not support trials.
     * @throws InvalidArgumentException When the number of days is negative.
     */
    public function trialDays(int $days): static;

    /**
     * Set the date the trial should end.
     *
     * @throws UnsupportedOperationException When the provider does not support trials.
     */
    public function trialUntil(DateTimeInterface $date): static;

    /**
     * Set the quantity of the subscription.
     *
     * @throws UnsupportedOperationException When the provider bills no per-subscription quantity.
     * @throws InvalidArgumentException When the quantity is not positive.
     */
    public function quantity(int $quantity): static;

    /**
     * Attach arbitrary metadata to the subscription.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws UnsupportedOperationException When the provider stores no subscription metadata.
     */
    public function withMetadata(array $metadata): static;

    /**
     * Create the subscription.
     *
     * @param  string|null  $paymentMethod  The payment method identifier.
     * @param  array<string, mixed>  $options
     *
     * @throws PaymentFailedException When the initial payment is declined.
     * @throws UnsupportedOperationException When the builder was given something the provider cannot bill on.
     * @throws CashierException When the gateway call fails.
     */
    public function create(?string $paymentMethod = null, array $options = []): Subscription;

    /**
     * Add the subscription without collecting payment upfront.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws UnsupportedOperationException When the provider cannot create a subscription without payment.
     * @throws CashierException When the gateway call fails.
     */
    public function add(array $options = []): Subscription;
}
