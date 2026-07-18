<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'production';
$absoluteLifetime = getenv('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS');
if ($absoluteLifetime === false || trim($absoluteLifetime) === '') {
    if ($environment === 'production') {
        throw new RuntimeException('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS is required in production.');
    }
    $absoluteLifetime = '28800';
}
if (filter_var($absoluteLifetime, FILTER_VALIDATE_INT, ['options' => ['min_range' => 901]]) === false) {
    throw new RuntimeException('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS must be an integer greater than the 900 second idle timeout.');
}

return [
    'session_idle_timeout_seconds' => 900,
    'session_absolute_timeout_seconds' => (int) $absoluteLifetime,
    'two_factor_ttl_seconds' => 600,
    'two_factor_max_attempts' => 5,
    'two_factor_resend_seconds' => 60,
    // Configurable planned defaults; owner approval is required before production.
    'login_ip_limit' => (int) (getenv('AUTH_LOGIN_IP_LIMIT') ?: 10),
    'login_account_limit' => (int) (getenv('AUTH_LOGIN_ACCOUNT_LIMIT') ?: 5),
    'login_window_seconds' => (int) (getenv('AUTH_LOGIN_WINDOW_SECONDS') ?: 900),
    'lockout_seconds' => (int) (getenv('AUTH_LOCKOUT_SECONDS') ?: 900),
    'cookie_secure' => filter_var(getenv('SESSION_COOKIE_SECURE') ?: false, FILTER_VALIDATE_BOOL),
    'rate_limit_pepper' => getenv('AUTH_RATE_LIMIT_PEPPER') ?: '',
];
