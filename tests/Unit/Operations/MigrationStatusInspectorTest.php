<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use App\Infrastructure\Database\MigrationStatusDatabase;
use App\Infrastructure\Database\MigrationStatusInspector;
use PHPUnit\Framework\TestCase;

final class MigrationStatusInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/migration-status-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAllMigrationsAppliedPasses(): void
    {
        $this->migration('002_second.sql');
        $this->migration('001_first.sql');

        $result = (new MigrationStatusInspector(
            new RecordingMigrationStatusDatabase(true, ['001_first.sql', '002_second.sql']),
            $this->directory,
        ))->inspect();

        self::assertSame('ok', $result->status);
        self::assertSame(0, $result->exitCode());
        self::assertSame([], $result->pending);
        self::assertSame([], $result->unknownApplied);
        self::assertSame(2, $result->expectedCount);
        self::assertSame(2, $result->appliedCount);
    }

    public function testOnePendingMigrationIsReportedAndFails(): void
    {
        $this->migration('001_first.sql');
        $this->migration('002_second.sql');

        $result = (new MigrationStatusInspector(
            new RecordingMigrationStatusDatabase(true, ['001_first.sql']),
            $this->directory,
        ))->inspect();

        self::assertSame('drift', $result->status);
        self::assertSame(1, $result->exitCode());
        self::assertSame(['002_second.sql'], $result->pending);
    }

    public function testMultiplePendingMigrationsAreDeterministic(): void
    {
        $this->migration('020_last.sql');
        $this->migration('003_middle.sql');
        $this->migration('001_first.sql');

        $result = (new MigrationStatusInspector(
            new RecordingMigrationStatusDatabase(true, ['001_first.sql']),
            $this->directory,
        ))->inspect();

        self::assertSame(['003_middle.sql', '020_last.sql'], $result->pending);
    }

    public function testUnknownAppliedVersionIsReportedAndFails(): void
    {
        $this->migration('001_first.sql');

        $result = (new MigrationStatusInspector(
            new RecordingMigrationStatusDatabase(true, ['900_unknown.sql', '001_first.sql']),
            $this->directory,
        ))->inspect();

        self::assertSame(1, $result->exitCode());
        self::assertSame(['900_unknown.sql'], $result->unknownApplied);
    }

    public function testMissingTableFailsWithoutReadingAppliedVersions(): void
    {
        $this->migration('001_first.sql');
        $database = new RecordingMigrationStatusDatabase(false, []);

        $result = (new MigrationStatusInspector($database, $this->directory))->inspect();

        self::assertSame('missing_migrations_table', $result->status);
        self::assertSame(1, $result->exitCode());
        self::assertSame(['001_first.sql'], $result->pending);
        self::assertSame(['migrationsTableExists'], $database->calls);
    }

    public function testInspectionDoesNotChangeMigrationFilesOrInvokeMutation(): void
    {
        $this->migration('001_first.sql', 'CREATE TABLE forbidden_example (id INT);');
        $before = hash_file('sha256', $this->directory . '/001_first.sql');
        $database = new RecordingMigrationStatusDatabase(true, ['001_first.sql']);

        (new MigrationStatusInspector($database, $this->directory))->inspect();

        self::assertSame($before, hash_file('sha256', $this->directory . '/001_first.sql'));
        self::assertSame(['migrationsTableExists', 'appliedVersions'], $database->calls);
    }

    public function testCustomMigrationDirectoryIsRespected(): void
    {
        $this->migration('custom_001.sql');

        $result = (new MigrationStatusInspector(
            new RecordingMigrationStatusDatabase(true, []),
            $this->directory,
        ))->inspect();

        self::assertSame(['custom_001.sql'], $result->pending);
    }

    public function testMachineReadableOutputContainsNoCredentialValues(): void
    {
        $this->migration('001_first.sql');
        $credentialMarker = 'db-password-must-not-leak';
        $result = (new MigrationStatusInspector(
            new RecordingMigrationStatusDatabase(true, ['001_first.sql'], $credentialMarker),
            $this->directory,
        ))->inspect();

        $json = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($credentialMarker, $json);
    }

    public function testCliAndPdoAdapterHaveReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $cli = file_get_contents($root . '/bin/migration-status.php');
        $adapter = file_get_contents($root . '/src/Infrastructure/Database/PdoMigrationStatusDatabase.php');
        self::assertIsString($cli);
        self::assertIsString($adapter);

        self::assertStringNotContainsString('Migrator', $cli);
        self::assertStringContainsString('MigrationStatusInspector', $cli);
        self::assertStringContainsString('MIGRATION_DIRECTORY', $cli);
        self::assertStringContainsString('information_schema.tables', $adapter);
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i',
            $adapter,
        );
    }

    private function migration(string $name, string $contents = '-- migration'): void
    {
        file_put_contents($this->directory . '/' . $name, $contents);
    }
}

final class RecordingMigrationStatusDatabase implements MigrationStatusDatabase
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<string> $versions */
    public function __construct(
        private readonly bool $tableExists,
        private readonly array $versions,
        private readonly string $irrelevantCredential = '',
    ) {
    }

    public function migrationsTableExists(): bool
    {
        $this->calls[] = 'migrationsTableExists';

        return $this->tableExists;
    }

    public function appliedVersions(): array
    {
        $this->calls[] = 'appliedVersions';

        return $this->versions;
    }
}
