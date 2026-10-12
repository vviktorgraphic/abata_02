<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

interface MigrationStatusDatabase
{
    public function migrationsTableExists(): bool;

    /** @return list<string> */
    public function appliedVersions(): array;
}
