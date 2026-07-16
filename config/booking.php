<?php

declare(strict_types=1);

return [
    'minimum_nights' => 1,
    'maximum_nights' => 30,
    'booking_horizon_days' => 365,
    'availability_query_max_days' => 93,
    'blocking_statuses' => ['confirmed'],
    'create_body_max_bytes' => (int) (getenv('BOOKING_BODY_MAX_BYTES') ?: 32768),
    'create_rate_limit' => (int) (getenv('BOOKING_RATE_LIMIT') ?: 10),
    'create_rate_window_seconds' => (int) (getenv('BOOKING_RATE_WINDOW_SECONDS') ?: 60),
    'create_rate_lockout_seconds' => (int) (getenv('BOOKING_RATE_LOCKOUT_SECONDS') ?: 60),
    'trusted_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', getenv('BOOKING_TRUSTED_ORIGINS') ?: 'http://localhost:8080')
    ))),
];

