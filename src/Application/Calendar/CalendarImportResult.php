<?php

declare(strict_types=1);

namespace App\Application\Calendar;

final readonly class CalendarImportResult
{
    /** @param list<string> $warnings @param list<string> $errors */
    public function __construct(
        public string $status,
        public int $imported,
        public int $duplicates,
        public array $warnings,
        public array $errors,
        public int $updated = 0,
        public int $inactivated = 0,
        public int $graceInactivated = 0,
        public int $retries = 0,
        public int $recoveredRuns = 0,
    ) {
    }
}
