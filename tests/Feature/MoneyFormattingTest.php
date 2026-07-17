<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use InvalidArgumentException;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\Facades\Cashier;
use Isapp\CashierSupport\Tests\TestCase;
use Money\Currency;

/**
 * The money-formatting API (#32): a locale-aware, symbol-bearing renderer over raw minor
 * units, backed by moneyphp/money — no float arithmetic, decimals per ISO-4217.
 *
 * ICU renders the gap between number and symbol as a non-breaking / narrow space that
 * varies by ICU version; whitespace is normalised so the assertions track the digits and
 * symbol, not the byte between them.
 */
class MoneyFormattingTest extends TestCase
{
    private function normalize(string $value): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
    }

    public function test_it_formats_with_locale_and_symbol(): void
    {
        // AC-1 — no driver is configured; formatting is driver-independent.
        $this->assertSame('12,34 €', $this->normalize(Cashier::formatAmount(1234, 'EUR', 'de_DE')));
    }

    public function test_it_accepts_a_currency_value_object(): void
    {
        $this->assertSame('12,34 €', $this->normalize(Cashier::formatAmount(1234, new Currency('EUR'), 'de_DE')));
    }

    public function test_a_zero_decimal_currency_renders_without_decimals(): void
    {
        // AC-2 — JPY has no minor units; 1234 is ¥1,234, not ¥12.34.
        $this->assertSame('¥1,234', $this->normalize(Cashier::formatAmount(1234, 'JPY', 'en')));
    }

    public function test_a_three_decimal_currency_renders_three_decimals(): void
    {
        // AC-3 — BHD has three minor units; 1234 fils is 1.234 BHD. The decimals come from
        // ISOCurrencies, not a hand-maintained table.
        $this->assertStringContainsString('1.234', $this->normalize(Cashier::formatAmount(1234, 'BHD', 'en')));
    }

    public function test_a_custom_formatter_overrides_the_output(): void
    {
        // AC-5 — the callback shape mirrors Cashier::formatCurrencyUsing().
        Cashier::formatCurrencyUsing(
            static fn (int $amount, Currency|string|null $currency, ?string $locale, array $options): string => "RAW {$amount}",
        );

        $this->assertSame('RAW 1234', Cashier::formatAmount(1234, 'EUR', 'de_DE'));
    }

    public function test_a_wide_currency_hydrates_and_round_trips_in_a_dto(): void
    {
        // AC-4 — KRW is outside the old 15-value whitelist; it must be expressible now.
        $payment = Payment::from([
            'id' => 'pay_krw',
            'amount' => 1500,
            'currency' => 'KRW',
            'status' => 'succeeded',
        ]);

        $this->assertSame('KRW', $payment->currency->getCode());
        $this->assertSame('KRW', $payment->toArray()['currency']);
    }

    public function test_an_unknown_currency_is_rejected_at_the_cast_boundary(): void
    {
        // AC-6 — a code no ISO-4217 currency uses is a malformed argument: SPL
        // InvalidArgumentException, per .claude/rules/exceptions.md, not a catchable failure.
        $this->expectException(InvalidArgumentException::class);

        Payment::from([
            'id' => 'pay_bad',
            'amount' => 1500,
            'currency' => 'ZZZ',
            'status' => 'succeeded',
        ]);
    }
}
