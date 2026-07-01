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
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="amount">{{ $line->quantity }}</td>
                    <td class="amount">{{ $currencyCode }} {{ $money($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="amount">{{ $currencyCode }} {{ $money($invoice->amount) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
