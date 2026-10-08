<?php

declare(strict_types=1);

$percent = getenv('PAYMENT_REQUEST_ADVANCE_PERCENT');
return [
    'beneficiary' => trim(getenv('PAYMENT_REQUEST_BENEFICIARY') ?: ''),
    'bank_account' => trim(getenv('PAYMENT_REQUEST_BANK_ACCOUNT') ?: ''),
    'bank_name' => trim(getenv('PAYMENT_REQUEST_BANK_NAME') ?: ''),
    'swift_bic' => trim(getenv('PAYMENT_REQUEST_SWIFT_BIC') ?: ''),
    'advance_percent' => $percent === false || $percent === '' ? 50
        : (preg_match('/\A\d{1,3}\z/', $percent) === 1 ? (int) $percent : 0),
];
