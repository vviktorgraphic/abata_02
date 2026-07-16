<?php

declare(strict_types=1);

$required = static function (string $name): string {
    $value = $_ENV[$name] ?? getenv($name);

    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(sprintf('Required environment variable %s is missing or empty.', $name));
    }

    return $value;
};

$port = filter_var($required('DB_PORT'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);

if ($port === false) {
    throw new RuntimeException('DB_PORT must be an integer between 1 and 65535.');
}

return [
    'host' => $required('DB_HOST'),
    'port' => $port,
    'database' => $required('DB_DATABASE'),
    'username' => $required('DB_USERNAME'),
    'password' => $required('DB_PASSWORD'),
];
