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
        self::assertStringContainsString('webrooton kívüli, jogosultságszűkített wrapperből', $contents);
        self::assertStringContainsString('forward-only', $contents);
        self::assertStringContainsString('SPF', $contents);
        self::assertStringContainsString('DKIM', $contents);
        self::assertStringContainsString('DMARC', $contents);
        self::assertStringContainsString('nem tölt be `.env` fájlt', $contents);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
