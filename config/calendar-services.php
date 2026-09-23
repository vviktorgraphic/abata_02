<?php
declare(strict_types=1);

use App\Application\Calendar\{BudapestCalendarSyncClock, CalendarImportService, CalendarRetryPolicy, IcsParser, SecureCalendarFeedFetcher};
use App\Infrastructure\Calendar\{CurlCalendarFeedHttpClient, NativeCalendarHostResolver, NativeCalendarSleeper};
use App\Infrastructure\Persistence\Calendar\{PdoCalendarSourceRepository, PdoCalendarSyncLogRepository, PdoCalendarSyncLock, PdoExternalCalendarEventRepository};

return static function (PDO $pdo): CalendarImportService {
    $config = require __DIR__ . '/calendar.php';
    return new CalendarImportService(
        new PdoCalendarSourceRepository($pdo), new PdoCalendarSyncLogRepository($pdo), new PdoExternalCalendarEventRepository($pdo),
        new SecureCalendarFeedFetcher(new CurlCalendarFeedHttpClient(), new NativeCalendarHostResolver(), $config['timeout_seconds']),
        new IcsParser(), new BudapestCalendarSyncClock(), new PdoCalendarSyncLock($pdo),
        new CalendarRetryPolicy($config['max_retries'], $config['backoff_seconds']), new NativeCalendarSleeper(), $config['grace_seconds'],
    );
};
