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
 * **String-backed, not merely backed.** `BackedEnum` permits `int` and the language cannot
 * narrow it here, so this is the only place the requirement can be stated: an int-backed
 * implementation type-checks and then fails silently, because
 * `Concerns\ManagesPaymentMethods::hasPaymentMethod('card')` compares the caller's string
 * against `->value` — int never equals string, so the answer is a confident `false` and
 * `deletePaymentMethods('card')` deletes nothing. Silence with the app's intent on the floor
 * is what `.claude/rules/smart-stubs.md` is about, and it is why a type here is a `string`.
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
