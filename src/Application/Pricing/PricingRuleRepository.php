<?php

declare(strict_types=1);

namespace App\Application\Pricing;

interface PricingRuleRepository
{
    /** @param array<string, mixed> $values */
    public function create(array $values, int $adminId): int;

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values, int $adminId): bool;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @return list<array<string, mixed>> */
    public function listAll(bool $includeInactive = true): array;

    public function setActive(int $id, bool $active, int $adminId): bool;

    /** @return list<array<string, mixed>> */
    public function findApplicable(string $type, string $arrivalDate, string $departureDate, int $nights): array;

    public function hasEqualPriorityConflict(
        string $type,
        string $validFrom,
        string $validUntil,
        int $priority,
        ?int $exceptId = null,
    ): bool;
}
