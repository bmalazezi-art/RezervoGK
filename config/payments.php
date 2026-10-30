<?php

return [
    'bank' => [
        'enabled' => (bool) env('BANK_TRANSFER_ENABLED', false),
        'beneficiary' => env('BANK_TRANSFER_BENEFICIARY', ''),
        'bankName' => env('BANK_TRANSFER_BANK_NAME', ''),
        'account' => env('BANK_TRANSFER_ACCOUNT', ''),
        'bic' => env('BANK_TRANSFER_BIC', ''),
    ],
];
