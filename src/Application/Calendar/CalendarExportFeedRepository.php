<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use App\Domain\Calendar\IcalExportEvent;

interface CalendarExportFeedRepository
{
    /** @return list<IcalExportEvent> */
    public function exportableEvents(): array;
}
