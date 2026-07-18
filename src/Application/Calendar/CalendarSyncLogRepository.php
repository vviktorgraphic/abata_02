<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface CalendarSyncLogRepository
{
    public function start(int $sourceId, \DateTimeImmutable $startedAt): int;

    /** @param list<string> $warnings @param list<string> $errors */
    public function finish(int $id, string $status, \DateTimeImmutable $finishedAt, int $imported, int $exported, array $warnings, array $errors): void;

    /** @return list<array<string, mixed>> */
    public function recent(?int $sourceId = null, int $limit = 100): array;
}
