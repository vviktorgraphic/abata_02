<?php

declare(strict_types=1);

return [
    'session_idle_timeout_seconds' => 900,
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
