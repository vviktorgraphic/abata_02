<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BlockedPeriod;
use DateTimeImmutable;
use DateTimeZone;

final readonly class BlockedPeriodService
{
    public function __construct(private BlockedPeriodManagementRepository $repository)
    {
    }

    public function create(string $start, string $end, string $reason, ?string $note, int $adminId): BlockedPeriodCreation
    {
        $timezone = new DateTimeZone('Europe/Budapest');
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $timezone);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $timezone);
        if ($startDate === false || $endDate === false
            || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
            throw new \App\Domain\Booking\BlockedPeriodInvalid('Dates must use the YYYY-MM-DD format.');
        }

        return $this->repository->create(new BlockedPeriod($startDate, $endDate, trim($reason), $note), $adminId);
    }

    public function remove(int $id, int $adminId): void
    {
        if ($id < 1 || $adminId < 1) {
            throw new \InvalidArgumentException('Positive identifiers are required.');
        }
        $this->repository->remove($id, $adminId);
    }
}
