<?php

declare(strict_types=1);

namespace App\Application\Booking;

use DateTimeImmutable;

final readonly class AdminBookingListQuery
{
    public ?string $status;
    public ?DateTimeImmutable $arrivalFrom;
    public ?DateTimeImmutable $arrivalUntil;
    public ?DateTimeImmutable $createdFrom;
    public ?DateTimeImmutable $createdUntil;
    public ?string $search;
    public int $page;
    public int $pageSize;

    /** @param array{status?: ?string, arrivalFrom?: ?DateTimeImmutable, arrivalUntil?: ?DateTimeImmutable, createdFrom?: ?DateTimeImmutable, createdUntil?: ?DateTimeImmutable, search?: ?string, page?: int, pageSize?: int} $filters */
    public function __construct(array $filters = [])
    {
        $this->status = $filters['status'] ?? null;
        $this->arrivalFrom = $filters['arrivalFrom'] ?? null;
        $this->arrivalUntil = $filters['arrivalUntil'] ?? null;
        $this->createdFrom = $filters['createdFrom'] ?? null;
        $this->createdUntil = $filters['createdUntil'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $this->search = $search === '' ? null : $search;
        $this->page = $filters['page'] ?? 1;
        $this->pageSize = $filters['pageSize'] ?? 20;

        if ($this->status !== null && !in_array($this->status, ['pending', 'confirmed', 'rejected', 'cancelled', 'invalidated'], true)) {
            throw new \InvalidArgumentException('Invalid booking status filter.');
        }
        if ($this->page < 1 || $this->pageSize < 1 || $this->pageSize > 100) {
            throw new \InvalidArgumentException('Invalid booking pagination.');
        }
        if ($this->arrivalFrom !== null && $this->arrivalUntil !== null && $this->arrivalFrom > $this->arrivalUntil) {
            throw new \InvalidArgumentException('Invalid arrival date range.');
        }
        if ($this->createdFrom !== null && $this->createdUntil !== null && $this->createdFrom > $this->createdUntil) {
            throw new \InvalidArgumentException('Invalid creation date range.');
        }
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
