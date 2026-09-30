<?php
declare(strict_types=1);
namespace App\Application\Calendar;

interface CalendarSleeper { public function sleep(int $seconds): void; }
