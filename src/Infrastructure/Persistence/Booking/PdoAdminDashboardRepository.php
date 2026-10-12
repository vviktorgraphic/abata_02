<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\AdminDashboardOverview;
use App\Application\Booking\AdminDashboardRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoAdminDashboardRepository implements AdminDashboardRepository
{
    public const QUERY_COUNT = 4;
    private const UPCOMING_LIMIT = 5;

    public function __construct(private PDO $pdo)
    {
    }

    public function fetch(DateTimeImmutable $today): AdminDashboardOverview
    {
        $budapestToday = $today->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d');
        $through = $today->setTimezone(new DateTimeZone('Europe/Budapest'))->modify('+7 days')->format('Y-m-d');

        $counts = $this->fetchCounts($budapestToday);
        $arrivals = $this->fetchDailyList('arrival_date', $budapestToday);
        $departures = $this->fetchDailyList('departure_date', $budapestToday);
        $upcoming = $this->fetchUpcoming($budapestToday, $through);

        return new AdminDashboardOverview(
            $counts['pending_count'],
            $counts['arrivals_today_count'],
            $counts['departures_today_count'],
            $counts['current_guests_count'],
            $arrivals,
            $departures,
            array_slice($upcoming, 0, self::UPCOMING_LIMIT),
            count($upcoming) > self::UPCOMING_LIMIT,
        );
    }

    /** @return array{pending_count: int, arrivals_today_count: int, departures_today_count: int, current_guests_count: int} */
    private function fetchCounts(string $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN status = :pending_status THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN status = :arrival_status AND arrival_date = :arrival_today THEN 1 ELSE 0 END), 0) AS arrivals_today_count,
                COALESCE(SUM(CASE WHEN status = :departure_status AND departure_date = :departure_today THEN 1 ELSE 0 END), 0) AS departures_today_count,
                COALESCE(SUM(CASE WHEN status = :current_status AND arrival_date <= :current_start AND departure_date > :current_end THEN 1 ELSE 0 END), 0) AS current_guests_count
             FROM bookings'
        );
        $statement->execute([
            'pending_status' => 'pending',
            'arrival_status' => 'confirmed',
            'arrival_today' => $today,
            'departure_status' => 'confirmed',
            'departure_today' => $today,
            'current_status' => 'confirmed',
            'current_start' => $today,
            'current_end' => $today,
        ]);
        $row = $statement->fetch();

        return [
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'arrivals_today_count' => (int) ($row['arrivals_today_count'] ?? 0),
            'departures_today_count' => (int) ($row['departures_today_count'] ?? 0),
            'current_guests_count' => (int) ($row['current_guests_count'] ?? 0),
        ];
    }

    /** @return list<array{reference: string, contact_name: string, arrival_date: string, departure_date: string, nights: int, adults: int, children: int, party_size: int}> */
    private function fetchDailyList(string $dateColumn, string $today): array
    {
        if (!in_array($dateColumn, ['arrival_date', 'departure_date'], true)) {
            throw new \InvalidArgumentException('Unsupported dashboard date column.');
        }

        $statement = $this->pdo->prepare(
            "SELECT reference, guest_name AS contact_name, arrival_date, departure_date,
                    DATEDIFF(departure_date, arrival_date) AS nights,
                    adults, children, adults + children AS party_size
             FROM bookings
             WHERE status = :status AND {$dateColumn} = :today
             ORDER BY {$dateColumn} ASC, id ASC"
        );
        $statement->execute(['status' => 'confirmed', 'today' => $today]);

        return $this->mapRows($statement->fetchAll());
    }

    /** @return list<array{reference: string, contact_name: string, arrival_date: string, departure_date: string, nights: int, adults: int, children: int, party_size: int}> */
    private function fetchUpcoming(string $today, string $through): array
    {
        $statement = $this->pdo->prepare(
            'SELECT reference, guest_name AS contact_name, arrival_date, departure_date,
                    DATEDIFF(departure_date, arrival_date) AS nights,
                    adults, children, adults + children AS party_size
             FROM bookings
             WHERE status = :status
               AND arrival_date > :today
               AND arrival_date <= :through
             ORDER BY arrival_date ASC, id ASC
             LIMIT 6'
        );
        $statement->execute(['status' => 'confirmed', 'today' => $today, 'through' => $through]);

        return $this->mapRows($statement->fetchAll());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{reference: string, contact_name: string, arrival_date: string, departure_date: string, nights: int, adults: int, children: int, party_size: int}>
     */
    private function mapRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'reference' => (string) $row['reference'],
            'contact_name' => (string) $row['contact_name'],
            'arrival_date' => (string) $row['arrival_date'],
            'departure_date' => (string) $row['departure_date'],
            'nights' => (int) $row['nights'],
            'adults' => (int) $row['adults'],
            'children' => (int) $row['children'],
            'party_size' => (int) $row['party_size'],
        ], $rows);
    }
}
