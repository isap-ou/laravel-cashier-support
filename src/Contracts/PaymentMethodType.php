<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Contracts;

use BackedEnum;
use IsapOu\EnumHelpers\Contracts\HasLabel;

/**
 * Contract for a payment method type.
 *
 * The set of payment method types is provider-specific (e.g. card, sepa,
 * ideal, revolut_pay, ...), so this package does not enumerate them. Instead
 * each concrete provider ships a string-backed enum implementing this contract,
 * and DTOs/operations type-hint the interface rather than a fixed enum.
 *
 * Example provider implementation:
 *
 *     enum AcmePaymentMethodType: string implements PaymentMethodType
 *     {
 *         use HasCashierLabel;
 *
 *         case Card = 'card';
 *         case Wallet = 'wallet';
 *     }
 */
interface PaymentMethodType extends BackedEnum, HasLabel {}
