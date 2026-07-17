@php
    /** @var \Isapp\CashierSupport\DTO\Invoice $invoice */
    $minor = $invoice->currency->minorUnits();
    // Integer-only money formatting — no float arithmetic on amounts.
    $money = static function (int $cents) use ($minor): string {
        if ($minor === 0) {
            return number_format($cents, 0);
        }

        $units = intdiv($cents, 10 ** $minor);
        $fraction = str_pad((string) abs($cents % (10 ** $minor)), $minor, '0', STR_PAD_LEFT);

        return number_format($units, 0).'.'.$fraction;
    };
    // Tax rate is basis points (2000 = 20.00%). Integer-only, display-only formatting.
    $rate = static function (int $basisPoints): string {
        $whole = intdiv($basisPoints, 100);
        $frac = $basisPoints % 100;

        return $frac === 0
            ? $whole.'%'
            : $whole.'.'.str_pad((string) $frac, 2, '0', STR_PAD_LEFT).'%';
    };
    $currencyCode = $invoice->currency->value;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number ?? $invoice->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        .header { display: flex; justify-content: space-between; margin-bottom: 32px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #e5e5e5; }
        td.amount, th.amount { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #1a1a1a; border-bottom: none; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Invoice</h1>
            <div class="muted">#{{ $invoice->number ?? $invoice->id }}</div>
            @if ($invoice->issuedAt)
                <div class="muted">{{ $invoice->issuedAt->toFormattedDateString() }}</div>
            @endif
        </div>
        <div>
            @if (!empty($seller['name']))
                <strong>{{ $seller['name'] }}</strong><br>
            @endif
            @if (!empty($seller['address']))
                <span class="muted">{{ $seller['address'] }}</span><br>
            @endif
            @if (!empty($seller['vat']))
                <span class="muted">VAT: {{ $seller['vat'] }}</span>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Qty</th>
                <th class="amount">Unit</th>
                <th class="amount">Amount</th>
                <th class="amount">Tax</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="amount">{{ $line->quantity }}</td>
                    <td class="amount">{{ $line->unitAmount !== null ? $currencyCode.' '.$money($line->unitAmount) : '—' }}</td>
                    <td class="amount">{{ $currencyCode }} {{ $money($line->amount) }}</td>
                    <td class="amount">
                        @if ($line->taxAmount !== null)
                            {{ $currencyCode }} {{ $money($line->taxAmount) }}@if ($line->taxRate !== null) <span class="muted">({{ $rate($line->taxRate) }})</span>@endif
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            @if ($invoice->subtotal !== null)
                <tr>
                    <td colspan="3">Subtotal</td>
                    <td class="amount">{{ $currencyCode }} {{ $money($invoice->subtotal) }}</td>
                    <td></td>
                </tr>
            @endif
            @if ($invoice->tax !== null)
                <tr>
                    <td colspan="3">Tax</td>
                    <td class="amount">{{ $currencyCode }} {{ $money($invoice->tax) }}</td>
                    <td></td>
                </tr>
            @endif
            @if ($invoice->discount !== null)
                <tr>
                    <td colspan="3">Discount</td>
                    <td class="amount">-{{ $currencyCode }} {{ $money($invoice->discount) }}</td>
                    <td></td>
                </tr>
            @endif
            <tr>
                <td colspan="3">Total</td>
                <td class="amount">{{ $currencyCode }} {{ $money($invoice->amount) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
