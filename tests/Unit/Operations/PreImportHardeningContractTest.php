<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class PreImportHardeningContractTest extends TestCase
{
    public function testLegacyImportCheckboxUsesCompactAccessibleConventionAndIsOptIn(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/admin/legacy-booking-import.php');

        self::assertStringContainsString('<label class="checkbox-label"><input type="checkbox" name="include_trash" value="1">', $template);
        self::assertStringContainsString('<span>Törölt sorok bevonása', $template);
        self::assertStringNotContainsString('name="include_trash" value="1" checked', $template);
        self::assertStringContainsString('trash', $template);
    }

    public function testPreflightUsesSharedBootstrapAndNeverPrintsSecretValues(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/preflight.php');

        self::assertStringContainsString('EnvironmentBootstrap::load($root)', $script);
        self::assertStringContainsString('public/static/css/admin.css', $script);
        self::assertStringContainsString('ConnectionFactory::create', $script);
        self::assertStringNotContainsString('DB_PASSWORD=', $script);
        self::assertStringNotContainsString('MAIL_PASSWORD=', $script);
        self::assertStringNotContainsString('AUTH_RATE_LIMIT_PEPPER=', $script);
        self::assertStringNotContainsString('file_get_contents($root . \'/.env\')', $script);
    }

    public function testReleasePackagingUsesTarGzAndExcludesTestsAndEnvironmentFiles(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/New-ReleasePackage.ps1');

        self::assertStringContainsString(".tar.gz", $script);
        self::assertStringContainsString("tar -czf", $script);
        self::assertStringContainsString("\$_.Name -eq '.env'", $script);
        self::assertStringContainsString("Join-Path \$payload 'tests'", $script);
        self::assertStringNotContainsString('Compress-Archive', $script);
    }

    public function testOperationalCliToolsUseApplicationBootstrapInsteadOfShellSourcing(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['admin-create.php', 'db-check.php', 'migrate.php', 'backup-database.php', 'restore-database.php', 'ical-sync.php', 'preflight.php'] as $name) {
            $contents = (string) file_get_contents($root . '/bin/' . $name);
            self::assertStringContainsString('EnvironmentBootstrap::load', $contents, $name);
            self::assertStringNotContainsString('source .env', strtolower($contents), $name);
            self::assertStringNotContainsString('set -a', strtolower($contents), $name);
        }
    }
}
