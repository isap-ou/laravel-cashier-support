<?php

declare(strict_types=1);

use Isapp\CashierSupport\Enums\Currency;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The ISO 4217 currency used when an operation does not specify one.
    | Must match a case of Isapp\CashierSupport\Enums\Currency.
    |
    */
    'currency' => env('CASHIER_CURRENCY', Currency::EUR->value),

    /*
    |--------------------------------------------------------------------------
    | Eloquent Models
    |--------------------------------------------------------------------------
    |
    | Concrete providers extend the abstract models shipped by this package.
    | Bind the concrete classes here so the package can resolve them.
    |
    */
    'models' => [
        'subscription' => null,
        'subscription_item' => null,
        'invoice' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Rendering
    |--------------------------------------------------------------------------
    |
    | Settings for the provider-independent local invoice generation
    | (Isapp\CashierSupport\Invoice\InvoiceBuilder + InvoiceRenderer).
    |
    */
    'invoices' => [
        'view' => 'cashier-support::invoice',
        'paper' => 'a4',
        'seller' => [
            'name' => env('CASHIER_SELLER_NAME'),
            'address' => env('CASHIER_SELLER_ADDRESS'),
            'vat' => env('CASHIER_SELLER_VAT'),
        ],
    ],
];
