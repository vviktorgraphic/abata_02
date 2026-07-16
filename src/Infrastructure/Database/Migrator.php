<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    public function migrate(): int
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                version VARCHAR(255) NOT NULL PRIMARY KEY,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = 0;

        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE version = :version');
        $record = $this->pdo->prepare('INSERT INTO migrations (version) VALUES (:version)');

        foreach ($files as $file) {
            $version = basename($file);
            $exists->execute(['version' => $version]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Cannot read migration: %s', $file));
            }

            // MySQL implicitly commits DDL, so record the version only after successful execution.
            $this->pdo->exec($sql);
            $record->execute(['version' => $version]);
            ++$applied;
            echo sprintf("Applied %s\n", $version);
        }

        return $applied;
    }
}
