<?php
declare(strict_types=1);
namespace App\Application\Calendar;

interface CalendarSyncLock
{
    public function acquire(int $sourceId): bool;
    public function release(int $sourceId): void;
}
