<?php

declare(strict_types=1);

use Isapp\CashierSupport\Enums\Currency;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Gateway Driver
    |--------------------------------------------------------------------------
    |
    | The gateway provider driver resolved by CashierManager when no driver is
    | given explicitly. A concrete provider package (e.g. cashier-revolut)
    | registers its driver via Cashier::extend('revolut', ...).
    |
    */
    'default' => env('CASHIER_DRIVER'),

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
        'customer' => null,
        'subscription' => null,
        'subscription_item' => null,
        'invoice' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | One route serves every registered driver. This is the PREFIX only — the route
    | appends the driver's name itself, so the final path is e.g.
    | "webhook/cashier/revolut". The name is the one the driver registered with
    | Cashier::extend(), so an unknown one is refused before any driver is asked.
    |
    | A prefix rather than a full path on purpose: the driver segment is not optional,
    | and a whole path here would let it be dropped — which registers fine and then
    | fails every single delivery. Stripe splits it the same way.
    |
    | Register the resulting URL with the gateway using `php artisan cashier:webhook`,
    | which reads it from the route rather than from here — change the prefix and the
    | command follows.
    |
    | The throttle is deliberate: both references ship without one.
    |
    | methods defaults to POST, which is what both references hardcode — but each of
    | them serves exactly one gateway, so that is two data points and not a law. Until
    | this package owned the route, a driver declared its own and could differ; this key
    | is what gives that back. A gateway that verifies its endpoint with a GET, or is
    | added later and simply differs, is configured here rather than being unreachable.
    |
    */
    'webhook' => [
        'prefix' => env('CASHIER_WEBHOOK_PREFIX', 'webhook/cashier'),
        'methods' => ['POST'],
        'middleware' => ['throttle:60,1'],
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
