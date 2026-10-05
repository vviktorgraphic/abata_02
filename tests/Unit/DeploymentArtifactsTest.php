<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DeploymentArtifactsTest extends TestCase
{
    public function testProductionEnvironmentTemplateContainsGuardsWithoutConcreteSecrets(): void
    {
        $contents = $this->read('.env.production.example');

        self::assertStringContainsString('APP_ENV=production', $contents);
        self::assertStringContainsString('APP_DEBUG=false', $contents);
        self::assertStringContainsString('SESSION_COOKIE_SECURE=true', $contents);
        self::assertStringContainsString('MAIL_ENCRYPTION=<tls-or-ssl>', $contents);
        self::assertStringContainsString('DB_PASSWORD=<secret-from-hosting-secret-store>', $contents);
        self::assertStringContainsString('AUTH_RATE_LIMIT_PEPPER=<long-random-secret>', $contents);
        self::assertStringNotContainsString('change-me', $contents);
        self::assertStringNotContainsString('localhost', $contents);
    }

    public function testProductionApacheTemplateRedirectsWithMethodPreservingStatusAndKeepsWebRootRouting(): void
    {
        $contents = $this->read('deploy/apache/public.htaccess.production');

        self::assertStringContainsString('RewriteCond %{HTTPS} !=on', $contents);
        self::assertStringContainsString('[R=308,L,NE]', $contents);
        self::assertStringContainsString('https://PRODUCTION_HOST%{REQUEST_URI}', $contents);
        self::assertStringNotContainsString('https://%{HTTP_HOST}', $contents);
        self::assertStringContainsString('RewriteRule ^ index.php [QSA,L]', $contents);
        self::assertStringNotContainsString('X-Forwarded-Proto', $contents);

        $development = $this->read('public/.htaccess');
        self::assertStringNotContainsString('https://', $development);
    }

    public function testDeploymentRunbookKeepsSecretsAndWritableDataOutsidePublicRoot(): void
    {
        $contents = $this->read('docs/15_DEPLOYMENT.md');

        self::assertStringContainsString('kizárólag a release `public/` könyvtára', $contents);
        self::assertStringContainsString('nem kell írhatónak lennie', $contents);
        self::assertStringContainsString('normál CLI használathoz nem kell', $contents);
        self::assertStringContainsString('forward-only', $contents);
        self::assertStringContainsString('SPF', $contents);
        self::assertStringContainsString('DKIM', $contents);
        self::assertStringContainsString('DMARC', $contents);
        self::assertStringContainsString('közös environment bootstrapet tölti be', $contents);
        self::assertStringContainsString('nem kell `source .env`', $contents);
        self::assertStringContainsString('tar -xzf', $contents);
        self::assertStringContainsString('bin/preflight.php', $contents);
        self::assertStringContainsString('backupot tartsd meg', $contents);
    }

    public function testReleasePackageUsesPosixTarAndIncludesStaticTreeContract(): void
    {
        $script = $this->read('tools/New-ReleasePackage.ps1');
        self::assertStringContainsString(".tar.gz", $script);
        self::assertStringContainsString('tar -czf', $script);
        self::assertStringContainsString("Join-Path \$payload 'tests'", $script);
        self::assertStringNotContainsString('Compress-Archive', $script);
        self::assertStringContainsString('public/static', $this->read('docs/15_DEPLOYMENT.md'));
        self::assertStringContainsString('fingerprinted booking CSS', $this->read('tools/Verify-ReleasePackage.ps1'));
        self::assertStringContainsString('Get-FileHash', $this->read('tools/Verify-ReleasePackage.ps1'));
        self::assertStringContainsString('static-assets.php', $this->read('tools/Update-StaticAssetFingerprints.ps1'));
        self::assertStringContainsString("Join-Path (Join-Path \$stage 'public')", $this->read('tools/Verify-ReleasePackage.ps1'));
    }

    public function testMigrationCompatibilityArtifactsCoverBothSupportedEngines(): void
    {
        $migration011 = $this->read('database/migrations/011_add_email_outbox_processing_status.sql');
        $migration013 = $this->read('database/migrations/013_add_pricing_policy_and_cancellation.sql');
        $matrix = $this->read('tools/Invoke-MigrationCompatibility.ps1');

        self::assertStringContainsString('DROP CONSTRAINT chk_email_outbox_status', $migration011);
        self::assertStringNotContainsString('DROP CHECK', $migration011);
        self::assertStringContainsString('DROP CONSTRAINT chk_pricing_rule_base_unit', $migration013);
        self::assertStringContainsString('mysql:8.0', $matrix);
        self::assertStringContainsString('mariadb:10.6.28', $matrix);
        self::assertStringContainsString('MIGRATION_DIRECTORY', $matrix);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
