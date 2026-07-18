<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Calendar;

use App\Application\Calendar\CalendarExportFeedRepository;
use App\Domain\Calendar\IcalExportEvent;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoCalendarExportFeedRepository implements CalendarExportFeedRepository
{
    private const TIMEZONE = 'Europe/Budapest';

    public function __construct(private PDO $pdo)
    {
    }

    public function exportableEvents(): array
    {
        $events = [];
        $bookings = $this->pdo->query(
            "SELECT reference, arrival_date, departure_date, updated_at FROM bookings WHERE status = 'confirmed' ORDER BY arrival_date, reference"
        );
        foreach ($bookings->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = $this->event(
                'booking:' . (string) $row['reference'],
                (string) $row['arrival_date'],
                (string) $row['departure_date'],
                (string) $row['updated_at'],
            );
        }

        $blocked = $this->pdo->query(
            'SELECT bp.id, bp.start_date, bp.end_date, bp.created_at
             FROM blocked_periods bp
             WHERE bp.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM external_calendar_events e WHERE e.blocked_period_id = bp.id
               )
             ORDER BY bp.start_date, bp.id'
        );
        foreach ($blocked->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = $this->event(
                'blocked:' . (string) $row['id'],
                (string) $row['start_date'],
                (string) $row['end_date'],
                (string) $row['created_at'],
            );
        }

        return $events;
    }

    private function event(string $stableSource, string $start, string $end, string $modified): IcalExportEvent
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        return new IcalExportEvent(
            hash('sha256', 'foglalasi-rendszer-ical:' . $stableSource) . '@calendar.local',
            new DateTimeImmutable($start . ' 00:00:00', $timezone),
            new DateTimeImmutable($end . ' 00:00:00', $timezone),
            new DateTimeImmutable($modified, $timezone),
        );
    }
}
