<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\MigrationStatusInspector;
use App\Infrastructure\Database\PdoMigrationStatusDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

try {
    App\Bootstrap\EnvironmentBootstrap::load($root);
    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Europe/Budapest');

    $migrationDirectory = getenv('MIGRATION_DIRECTORY');
    $migrationDirectory = is_string($migrationDirectory) && trim($migrationDirectory) !== ''
        ? trim($migrationDirectory)
        : $root . '/database/migrations';

    $pdo = ConnectionFactory::create(require $root . '/config/database.php');
    $result = (new MigrationStatusInspector(
        new PdoMigrationStatusDatabase($pdo),
        $migrationDirectory,
    ))->inspect();

    fwrite(STDOUT, json_encode(
        $result->toArray(),
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL);
    exit($result->exitCode());
} catch (Throwable) {
    fwrite(STDERR, json_encode(
        ['status' => 'error', 'message' => 'Migration status inspection failed.'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL);
    exit(2);
}
