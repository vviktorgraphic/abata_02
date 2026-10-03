<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Database\MysqldumpCapabilities;
use PHPUnit\Framework\TestCase;

final class MysqldumpCapabilitiesTest extends TestCase
{
    public function testMysqlHelpEnablesOptionalFlag(): void
    {
        self::assertTrue(MysqldumpCapabilities::supportsSetGtidPurgedFromHelp("Usage: mysqldump [OPTIONS]\n  --set-gtid-purged[=name]\n"));
    }

    public function testMariaDbHelpOmitsUnsupportedFlag(): void
    {
        self::assertFalse(MysqldumpCapabilities::supportsSetGtidPurgedFromHelp("Usage: mariadb-dump [OPTIONS]\n  --no-tablespaces\n"));
    }

    public function testMalformedHelpDoesNotGuessSupport(): void
    {
        self::assertFalse(MysqldumpCapabilities::supportsSetGtidPurgedFromHelp('capability command failed'));
    }

    public function testCapabilityFailureIsExplicitAndSecretFree(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Database/MysqldumpCapabilities.php');
        self::assertStringContainsString('capability detection failed', $source);
        self::assertStringNotContainsString('DB_PASSWORD', $source);
        self::assertStringNotContainsString('optionContents', $source);
    }

    public function testBackupScriptKeepsSecureOptionsAndNoPasswordCommandArgument(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/backup-database.php');
        foreach (['--single-transaction', '--quick', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4'] as $option) {
            self::assertStringContainsString($option, $script);
        }
        self::assertStringContainsString('MysqldumpCapabilities::supportsSetGtidPurged', $script);
        self::assertStringNotContainsString("'--password='", $script);
    }
}
