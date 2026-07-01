<?php

declare(strict_types=1);

return [
    'PaymentStatus' => [
        'Pending' => 'Pending',
        'Processing' => 'Processing',
        'Succeeded' => 'Succeeded',
        'Failed' => 'Failed',
        'Canceled' => 'Canceled',
        'Refunded' => 'Refunded',
    ],

    'SubscriptionStatus' => [
        'Active' => 'Active',
        'PastDue' => 'Past due',
        'Canceled' => 'Canceled',
        'Incomplete' => 'Incomplete',
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
