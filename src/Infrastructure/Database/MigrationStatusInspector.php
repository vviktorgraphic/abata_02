<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use RuntimeException;

final class MigrationStatusInspector
{
    public function __construct(
        private readonly MigrationStatusDatabase $database,
        private readonly string $migrationDirectory,
    ) {
    }

    public function inspect(): MigrationStatusResult
    {
        if (!is_dir($this->migrationDirectory) || !is_readable($this->migrationDirectory)) {
            throw new RuntimeException('The migration directory is unavailable.');
        }

        $files = glob(rtrim($this->migrationDirectory, '/\\') . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('The migration directory cannot be inspected.');
        }

        $expected = array_values(array_unique(array_map('basename', $files)));
        sort($expected, SORT_STRING);

        if (!$this->database->migrationsTableExists()) {
            return new MigrationStatusResult(
                'missing_migrations_table',
                count($expected),
                0,
                $expected,
                [],
            );
        }

        $applied = array_values(array_unique($this->database->appliedVersions()));
        sort($applied, SORT_STRING);
        $pending = array_values(array_diff($expected, $applied));
        $unknownApplied = array_values(array_diff($applied, $expected));

        return new MigrationStatusResult(
            $pending === [] && $unknownApplied === [] ? 'ok' : 'drift',
            count($expected),
            count($applied),
            $pending,
            $unknownApplied,
        );
    }
}
