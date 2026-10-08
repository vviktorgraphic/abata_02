<?php

declare(strict_types=1);

namespace App\Application\Booking;

use DateTimeImmutable;
use DateTimeZone;

final readonly class AdminMonthlyOccupancyQuery
{
    public const TIMEZONE = 'Europe/Budapest';

    public DateTimeImmutable $monthStart;
    public DateTimeImmutable $nextMonthStart;
    public string $month;
    public string $previousMonth;
    public string $nextMonth;
    public string $currentMonth;
    public string $label;
    public string $today;

    public function __construct(mixed $month, DateTimeImmutable $today)
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $localToday = $today->setTimezone($timezone);
        $selected = $month === null ? $localToday->format('Y-m') : $month;
        if (!is_string($selected) || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $selected) !== 1) {
            throw new \InvalidArgumentException('A megadott hónap érvénytelen.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m', $selected, $timezone);
        if ($start === false || $start->format('Y-m') !== $selected) {
            throw new \InvalidArgumentException('A megadott hónap érvénytelen.');
        }

        $this->monthStart = $start;
        $this->nextMonthStart = $start->modify('first day of next month');
        $this->month = $selected;
        $this->previousMonth = $start->modify('first day of previous month')->format('Y-m');
        $this->nextMonth = $this->nextMonthStart->format('Y-m');
        $this->currentMonth = $localToday->format('Y-m');
        $this->today = $localToday->format('Y-m-d');
        $this->label = sprintf('%s. %s', $start->format('Y'), self::monthNames()[(int) $start->format('n')]);
    }

    /** @return list<string> */
    public function dayDates(): array
    {
        $dates = [];
        for ($day = $this->monthStart; $day < $this->nextMonthStart; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /** @return array<int, string> */
    private static function monthNames(): array
    {
        return [
            1 => 'január', 'február', 'március', 'április', 'május', 'június',
            'július', 'augusztus', 'szeptember', 'október', 'november', 'december',
        ];
    }
}
