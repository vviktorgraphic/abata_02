<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use DateTimeImmutable;

final readonly class CalendarExportFeed
{
    public function __construct(
        private CalendarExportFeedRepository $events,
        private IcalExporter $exporter,
    ) {
    }

    public function render(DateTimeImmutable $generatedAt): string
    {
        return $this->exporter->export($this->events->exportableEvents(), $generatedAt);
    }
}
