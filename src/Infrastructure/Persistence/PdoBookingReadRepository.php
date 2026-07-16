<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Availability\BookingReadRepository;
use App\Domain\Booking\BookingPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoBookingReadRepository implements BookingReadRepository
{
    /** @param list<string> $blockingStatuses */
    public function __construct(private PDO $pdo, private array $blockingStatuses = ['confirmed'])
    {
    }

    public function findBlockingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statusPlaceholders = implode(', ', array_fill(0, count($this->blockingStatuses), '?'));
        $statement = $this->pdo->prepare(sprintf(
            'SELECT arrival_date, departure_date FROM bookings
             WHERE status IN (%s) AND arrival_date < ? AND departure_date > ?
             ORDER BY arrival_date',
            $statusPlaceholders,
        ));
        $statement->execute([
            ...$this->blockingStatuses,
            $to->format('Y-m-d'),
            $from->format('Y-m-d'),
        ]);

        return array_map(fn (array $row): BookingPeriod => $this->period($row), $statement->fetchAll());
    }

    /** @param array{arrival_date: string, departure_date: string} $row */
    private function period(array $row): BookingPeriod
    {
        $timezone = new DateTimeZone('Europe/Budapest');
        return new BookingPeriod(
            new DateTimeImmutable($row['arrival_date'] . ' 00:00:00', $timezone),
            new DateTimeImmutable($row['departure_date'] . ' 00:00:00', $timezone),
        );
    }
}

