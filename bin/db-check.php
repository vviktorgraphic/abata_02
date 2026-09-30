<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
App\Bootstrap\EnvironmentBootstrap::load($root);

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
