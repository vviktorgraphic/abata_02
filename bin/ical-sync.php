<?php
declare(strict_types=1);

use App\Application\Calendar\CalendarSyncWorker;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Calendar\PdoCalendarSourceRepository;

// CLI only; never print exceptions or PHP diagnostics containing provider secrets.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Budapest');
$root = dirname(__DIR__);
$emit = static function (array $record): void {
    $record['timestamp'] = (new DateTimeImmutable())->format(DATE_ATOM);
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};
try {
    require $root . '/vendor/autoload.php';
    App\Bootstrap\EnvironmentBootstrap::load($root);
    $pdo = ConnectionFactory::create(require $root . '/config/database.php');
    $factory = require $root . '/config/calendar-services.php';
    exit((new CalendarSyncWorker(new PdoCalendarSourceRepository($pdo), $factory($pdo)))->run($emit));
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"ical_worker_failed\",\"error\":\"configuration_or_infrastructure_failure\"}\n");
    exit(1);
}
