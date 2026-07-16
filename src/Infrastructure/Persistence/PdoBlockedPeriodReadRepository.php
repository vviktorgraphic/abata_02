<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Availability\BlockedPeriodReadRepository;
use App\Domain\Booking\BookingPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoBlockedPeriodReadRepository implements BlockedPeriodReadRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare(
            'SELECT start_date, end_date FROM blocked_periods
             WHERE is_active = TRUE AND start_date < :to_date AND end_date > :from_date
             ORDER BY start_date'
        );
        $statement->execute([
            'to_date' => $to->format('Y-m-d'),
            'from_date' => $from->format('Y-m-d'),
        ]);
        $timezone = new DateTimeZone('Europe/Budapest');

        return array_map(
            static fn (array $row): BookingPeriod => new BookingPeriod(
                new DateTimeImmutable($row['start_date'] . ' 00:00:00', $timezone),
                new DateTimeImmutable($row['end_date'] . ' 00:00:00', $timezone),
            ),
            $statement->fetchAll(),
        );
    }
}

