<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use Isapp\CashierSupport\Enums\Capability;

/**
 * The central gateway provider contract.
 *
 * A concrete package (cashier-revolut, cashier-adyen, ...) registers an
 * implementation as a driver via Cashier::extend(). Concerns resolve it
 * through CashierManager (Cashier::provider()) and delegate every billing
 * operation to it.
 *
 * It aggregates all operation contracts and additionally declares which
 * capabilities the provider supports.
 */
interface GatewayProvider extends ChargeOperations, CheckoutOperations, CustomerOperations, InvoiceOperations, PaymentMethodOperations, SubscriptionOperations, WebhookHandler
{
    /**
     * The capabilities this provider supports.
     *
     * @return array<int, Capability>
     */
    public function capabilities(): array;

    /**
     * Whether this provider supports the given capability.
     */
    public function supports(Capability $capability): bool;
}
