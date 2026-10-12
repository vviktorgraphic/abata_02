<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class StateSnapshotToolTest extends TestCase
{
    private string $temporaryRoot;
    private string $releaseRoot;
    private string $snapshotRoot;
    private string $backupFile;
    private string $checksumFile;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The snapshot core contract is exercised in the Linux application container.');
        }

        $this->temporaryRoot = sys_get_temp_dir() . '/state-snapshot-' . bin2hex(random_bytes(8));
        $this->releaseRoot = $this->temporaryRoot . '/release/release-test';
        $this->snapshotRoot = $this->temporaryRoot . '/snapshots';
        $backupRoot = $this->temporaryRoot . '/backups';
        mkdir($this->releaseRoot . '/var/cache', 0700, true);
        mkdir($this->releaseRoot . '/.git', 0700, true);
        mkdir($this->snapshotRoot, 0700, true);
        mkdir($backupRoot, 0700, true);
        file_put_contents($this->releaseRoot . '/.env', "SNAPSHOT_SECRET=super-secret-marker\n");
        file_put_contents($this->releaseRoot . '/index.php', "<?php echo 'fixture';\n");
        file_put_contents($this->releaseRoot . '/var/cache/runtime.cache', 'excluded cache');
        file_put_contents($this->releaseRoot . '/.git/config', 'excluded git metadata');
        $this->backupFile = $backupRoot . '/synthetic.sql';
        $this->checksumFile = $this->backupFile . '.sha256';
        file_put_contents($this->backupFile, "CREATE TABLE synthetic_fixture (id INT);\n");
        file_put_contents(
            $this->checksumFile,
            hash_file('sha256', $this->backupFile) . '  ' . basename($this->backupFile) . "\n",
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryRoot) && is_dir($this->temporaryRoot)) {
            $this->removeDirectory($this->temporaryRoot);
        }
    }

    public function testSuccessfulSnapshotHasExactVerifiedArtifactsAndPrivatePermissions(): void
    {
        $result = $this->runSnapshot('complete');
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringNotContainsString('super-secret-marker', $result['stdout'] . $result['stderr']);

        $final = $this->snapshotRoot . '/complete';
        self::assertDirectoryExists($final);
        $files = array_values(array_diff(scandir($final) ?: [], ['.', '..']));
        sort($files);
        self::assertSame([
            'SNAPSHOT.sha256', 'STATE.txt', 'database.sql', 'database.sql.sha256',
            'production.env', 'release.tar.gz',
        ], $files);
        self::assertSame(0700, fileperms($final) & 0777);
        foreach ($files as $file) {
            self::assertSame(0600, fileperms($final . '/' . $file) & 0777, $file);
            self::assertGreaterThan(0, filesize($final . '/' . $file), $file);
        }
        $this->assertManifestValid($final);

        $archive = $this->runProcess(['tar', '-tzf', $final . '/release.tar.gz']);
        self::assertSame(0, $archive['exit'], $archive['stderr']);
        self::assertStringContainsString('release-test/index.php', $archive['stdout']);
        self::assertStringNotContainsString('release-test/.env', $archive['stdout']);
        self::assertStringNotContainsString('release-test/.git', $archive['stdout']);
        self::assertStringNotContainsString('release-test/var/cache', $archive['stdout']);
    }

    public function testMissingDatabaseBackupFailsWithoutFinalOrTemporaryDirectory(): void
    {
        unlink($this->backupFile);
        $this->assertCleanFailure('missing-db');
    }

    public function testBadDatabaseChecksumFailsWithoutFinalDirectory(): void
    {
        file_put_contents($this->checksumFile, str_repeat('0', 64) . '  synthetic.sql' . "\n");
        $this->assertCleanFailure('bad-checksum');
    }

    public function testMissingReleaseRootFailsWithoutFinalDirectory(): void
    {
        $this->removeDirectory($this->releaseRoot);
        $this->assertCleanFailure('missing-release');
    }

    public function testMissingEnvironmentFileFailsWithoutFinalDirectory(): void
    {
        unlink($this->releaseRoot . '/.env');
        $this->assertCleanFailure('missing-env');
    }

    public function testSimulatedArchiveFailureCleansTemporaryDirectory(): void
    {
        $this->assertCleanFailure('archive-failure', 'archive');
    }

    public function testSimulatedManifestFailureCleansTemporaryDirectory(): void
    {
        $this->assertCleanFailure('manifest-failure', 'manifest');
    }

    public function testChecksumMustReferenceTheSuppliedBackupFilename(): void
    {
        file_put_contents(
            $this->checksumFile,
            hash_file('sha256', $this->backupFile) . '  another.sql' . "\n",
        );
        $this->assertCleanFailure('wrong-name');
    }

    public function testExistingFinalDirectoryIsNeverOverwritten(): void
    {
        mkdir($this->snapshotRoot . '/existing', 0700);
        file_put_contents($this->snapshotRoot . '/existing/marker', 'preserve');
        $result = $this->runSnapshot('existing');
        self::assertNotSame(0, $result['exit']);
        self::assertSame('preserve', file_get_contents($this->snapshotRoot . '/existing/marker'));
    }

    public function testPowerShellWrapperHasNoProductionDefaultsOrSecretOutput(): void
    {
        $wrapper = file_get_contents(dirname(__DIR__, 3) . '/tools/New-StateSnapshot.ps1');
        self::assertIsString($wrapper);
        foreach (['SshTarget', 'ReleaseRoot', 'SnapshotRoot', 'DatabaseBackupFile', 'DatabaseChecksumFile'] as $parameter) {
            self::assertStringContainsString('[string] $' . $parameter, $wrapper);
        }
        foreach (['we035.tarhely.com', '/home/abatahu', 'DB_PASSWORD', 'production.env'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $wrapper);
        }
        self::assertStringContainsString('BatchMode=yes', $wrapper);
        self::assertStringContainsString('PasswordAuthentication=no', $wrapper);
    }

    private function assertCleanFailure(string $snapshotId, string $simulation = ''): void
    {
        $result = $this->runSnapshot($snapshotId, $simulation);
        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString('super-secret-marker', $result['stdout'] . $result['stderr']);
        self::assertDirectoryDoesNotExist($this->snapshotRoot . '/' . $snapshotId);
        self::assertDirectoryDoesNotExist($this->snapshotRoot . '/.' . $snapshotId . '.tmp');
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    private function runSnapshot(string $snapshotId, string $simulation = ''): array
    {
        $command = [
            'bash', dirname(__DIR__, 3) . '/tools/New-StateSnapshot.Core.sh',
            '--release-root', $this->releaseRoot,
            '--snapshot-root', $this->snapshotRoot,
            '--database-backup', $this->backupFile,
            '--database-checksum', $this->checksumFile,
            '--snapshot-id', $snapshotId,
        ];
        if ($simulation !== '') {
            $command[] = '--local-test-failure';
            $command[] = $simulation;
        }

        return $this->runProcess($command);
    }

    /** @param list<string> $command @return array{exit:int, stdout:string, stderr:string} */
    private function runProcess(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function assertManifestValid(string $directory): void
    {
        $lines = file($directory . '/SNAPSHOT.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(5, $lines);
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^([a-f0-9]{64})  ([A-Za-z0-9.]+)$/', $line);
            [$hash, $name] = preg_split('/  /', $line, 2) ?: ['', ''];
            self::assertSame($hash, hash_file('sha256', $directory . '/' . $name), $name);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
