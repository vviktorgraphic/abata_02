<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'production';
$idleLifetime = getenv('ADMIN_SESSION_IDLE_TIMEOUT_SECONDS');
if ($idleLifetime === false || trim($idleLifetime) === '') {
    $idleLifetime = '1800';
}
if (filter_var($idleLifetime, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1800]]) === false) {
    throw new RuntimeException('ADMIN_SESSION_IDLE_TIMEOUT_SECONDS must be an integer of at least 1800 seconds.');
}
$idleLifetime = (int) $idleLifetime;
$absoluteLifetime = getenv('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS');
if ($absoluteLifetime === false || trim($absoluteLifetime) === '') {
    if ($environment === 'production') {
        throw new RuntimeException('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS is required in production.');
    }
    $absoluteLifetime = '28800';
}
if (filter_var($absoluteLifetime, FILTER_VALIDATE_INT) === false || (int) $absoluteLifetime <= $idleLifetime) {
    throw new RuntimeException('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS must be an integer greater than the configured idle timeout.');
}

return [
    'session_idle_timeout_seconds' => $idleLifetime,
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
