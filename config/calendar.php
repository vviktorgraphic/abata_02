<?php
declare(strict_types=1);

$integer = static function (string $key, int $default, int $minimum, int $maximum): int {
    $raw = $_ENV[$key] ?? getenv($key);
    $value = $raw === false ? $default : filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) {
        throw new RuntimeException('Invalid calendar configuration: ' . $key);
    }
    return $value;
};

return [
    'timeout_seconds' => $integer('ICAL_TIMEOUT_SECONDS', 10, 1, 120),
    'max_retries' => $integer('ICAL_MAX_RETRIES', 2, 0, 5),
    'backoff_seconds' => $integer('ICAL_BACKOFF_SECONDS', 1, 1, 30),
    'grace_seconds' => $integer('ICAL_MISSING_GRACE_SECONDS', 86400, 86400, 31536000),
];
