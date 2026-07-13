<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Carbon\CarbonImmutable;
use Isapp\CashierSupport\Enums\CheckoutMode;

/**
 * Contract for a hosted checkout session.
 *
 * Hosted checkout differs greatly between providers (redirect URL, embedded
 * widget token, client secret, ...), so this package does not fix its shape in
 * a DTO. Each provider returns its own object implementing this contract, and
 * operations type-hint the interface.
 *
 * A provider may additionally implement Illuminate\Contracts\Support\Responsable
 * to let the session be returned directly from a controller.
 */
interface CheckoutSession
{
    /**
     * The provider identifier of the checkout session.
     */
    public function id(): string;

    /**
     * The mode of the checkout session.
     */
    public function mode(): CheckoutMode;

    /**
     * The hosted redirect URL, if the provider uses a redirect flow.
     */
    public function url(): ?string;

    /**
     * When the session expires, if applicable.
     */
    public function expiresAt(): ?CarbonImmutable;

    /**
     * The secret a client-side SDK needs to take over the session, if the
     * provider uses one.
     *
     * Provider-neutral rather than a special case: Stripe's embedded checkout
     * has a client_secret, Adyen a sessionData, Braintree a client token,
     * Revolut an order token. Without it on the contract, an app using the
     * client-side flow has to type-hint the concrete driver class.
     */
    public function clientSecret(): ?string;
}
