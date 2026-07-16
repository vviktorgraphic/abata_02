<?php

declare(strict_types=1);

return [
    'host' => getenv('MAIL_HOST') ?: 'mailpit',
    'port' => (int) (getenv('MAIL_PORT') ?: 1025),
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'none',
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'from_email' => getenv('MAIL_FROM_EMAIL') ?: 'no-reply@abata.local',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'A Bata',
];

