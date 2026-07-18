<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface CalendarExportTokenRepository
{
    public function rotate(string $plainToken, \DateTimeImmutable $at): void;

    public function verify(string $plainToken): bool;

    /** @return array{created_at: string, rotated_at: ?string}|null */
    public function metadata(): ?array;
}
