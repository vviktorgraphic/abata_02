<?php
declare(strict_types=1);
namespace App\Infrastructure\Calendar;
use App\Application\Calendar\CalendarSleeper;

final class NativeCalendarSleeper implements CalendarSleeper
{
    public function sleep(int $seconds): void { sleep($seconds); }
}
