<?php

declare(strict_types=1);

return [
    'PaymentStatus' => [
        'Pending' => 'Pending',
        'RequiresPaymentMethod' => 'Requires payment method',
        'RequiresConfirmation' => 'Requires confirmation',
        'RequiresAction' => 'Requires action',
        'Processing' => 'Processing',
        'Succeeded' => 'Succeeded',
        'Failed' => 'Failed',
        'Canceled' => 'Canceled',
        'Refunded' => 'Refunded',
    ],

    'SubscriptionStatus' => [
        'Active' => 'Active',
        'PastDue' => 'Past due',
        'Unpaid' => 'Unpaid',
        'Canceled' => 'Canceled',
        'Incomplete' => 'Incomplete',
        'IncompleteExpired' => 'Incomplete expired',
        'Trialing' => 'Trialing',
        'Paused' => 'Paused',
    ],

    'RefundReason' => [
        'Duplicate' => 'Duplicate',
        'Fraudulent' => 'Fraudulent',
        'RequestedByCustomer' => 'Requested by customer',
        'Other' => 'Other',
    ],
];
