<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

final readonly class IcsCalendar
{
    /** @param list<IcsEvent> $events */
    public function __construct(public array $events)
    {
    }
}
