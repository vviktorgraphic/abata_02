<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($name) === false) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}

$config = require $root . '/config/database.php';

try {
    $pdo = ConnectionFactory::create($config);
    $pdo->query('SELECT 1')->fetchColumn();
    printf(
        "Database connection successful (host=%s, database=%s, user=%s).\n",
        $config['host'],
        $config['database'],
        $config['username'],
    );
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        sprintf(
            "Database connection failed (host=%s, database=%s, user=%s). Check .env and whether the MySQL volume was initialized with different credentials.\n",
            $config['host'],
            $config['database'],
            $config['username'],
        ),
    );
    exit(1);
}
