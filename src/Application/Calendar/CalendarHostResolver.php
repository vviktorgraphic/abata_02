<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface CalendarHostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
