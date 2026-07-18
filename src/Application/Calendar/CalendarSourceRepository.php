<?php

declare(strict_types=1);

namespace App\Application\Calendar;

interface CalendarSourceRepository
{
    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    public function create(string $name, string $provider, string $url, string $direction, bool $enabled, ?string $syncToken = null): int;

    public function update(int $id, string $name, string $provider, string $url, string $direction, bool $enabled, ?string $syncToken = null): void;

    public function delete(int $id): void;

    public function markSuccess(int $id, \DateTimeImmutable $at): void;

    public function markError(int $id, \DateTimeImmutable $at): void;
}
