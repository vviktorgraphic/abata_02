<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\MigrationStatusInspector;
use App\Infrastructure\Database\PdoMigrationStatusDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoMigrationStatusDatabaseTest extends TestCase
{
    public function testInspectionMatchesRepositoryAndDoesNotMutateDatabase(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database not configured.');
        }

        $root = dirname(__DIR__, 3);
        $pdo = ConnectionFactory::create(require $root . '/config/database.php');
        $before = $this->databaseFingerprint($pdo);

        $result = (new MigrationStatusInspector(
            new PdoMigrationStatusDatabase($pdo),
            $root . '/database/migrations',
        ))->inspect();

        self::assertSame('ok', $result->status);
        self::assertSame([], $result->pending);
        self::assertSame([], $result->unknownApplied);
        self::assertSame($before, $this->databaseFingerprint($pdo));
    }

    private function databaseFingerprint(PDO $pdo): string
    {
        $tables = $pdo->query(
            'SELECT table_name, engine, table_rows FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() ORDER BY table_name'
        )->fetchAll();
        $migrations = $pdo->query(
            'SELECT version, executed_at FROM migrations ORDER BY version'
        )->fetchAll();

        return hash('sha256', serialize([$tables, $migrations]));
    }
}
