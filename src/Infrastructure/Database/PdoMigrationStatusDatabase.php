<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final class PdoMigrationStatusDatabase implements MigrationStatusDatabase
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrationsTableExists(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $statement->execute(['table_name' => 'migrations']);

        return (int) $statement->fetchColumn() === 1;
    }

    public function appliedVersions(): array
    {
        $statement = $this->pdo->query('SELECT version FROM migrations ORDER BY version ASC');
        $versions = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $versions));
    }
}
