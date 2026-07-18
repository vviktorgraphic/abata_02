<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class DatabaseBackupScriptsTest extends TestCase
{
    public function testBackupScriptKeepsSecretsOutOfCommandArgumentsAndFinalizesAtomically(): void
    {
        $script = $this->script('backup-database.php');

        self::assertStringContainsString("\$password = \$required('DB_PASSWORD')", $script);
        self::assertStringContainsString("'--defaults-extra-file=' . \$optionFile", $script);
        self::assertStringNotContainsString("'--password='", $script);
        self::assertStringContainsString('chmod($optionFile, 0600)', $script);
        self::assertStringContainsString('chmod($temporaryDump, 0600)', $script);
        self::assertStringContainsString("rename(\$temporaryChecksum, \$finalChecksum)", $script);
        self::assertStringContainsString("rename(\$temporaryDump, \$finalDump)", $script);
        self::assertStringContainsString("hash_file('sha256', \$temporaryDump)", $script);
        self::assertStringContainsString('outside the repository and its public webroot', $script);
        self::assertStringNotContainsString("'--databases'", $script);
        self::assertStringContainsString("'--no-tablespaces'", $script);
        self::assertStringNotContainsString("'--routines'", $script);
        self::assertStringNotContainsString("'--events'", $script);
    }

    public function testRestoreRequiresExactConfirmationAndVerifiedChecksum(): void
    {
        $script = $this->script('restore-database.php');

        self::assertStringContainsString("\$confirmation !== 'RESTORE:' . \$database", $script);
        self::assertStringContainsString("hash_equals(\$matches[1]", $script);
        self::assertStringContainsString("\$backupFile . '.sha256'", $script);
        self::assertStringContainsString("'--defaults-extra-file=' . \$optionFile", $script);
        self::assertStringNotContainsString("'--password='", $script);
        self::assertStringContainsString("'--defaults-extra-file=' . \$optionFile, \$database", $script);
    }

    private function script(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/bin/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
