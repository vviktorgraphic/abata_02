<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
App\Bootstrap\EnvironmentBootstrap::load($root);

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Europe/Budapest');
$pdo = ConnectionFactory::create(require $root . '/config/database.php');
$count = (new Migrator($pdo, $root . '/database/migrations'))->migrate();
echo sprintf("Migration complete; %d migration(s) applied.\n", $count);

