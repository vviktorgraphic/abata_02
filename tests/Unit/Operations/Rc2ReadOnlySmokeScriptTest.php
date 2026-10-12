<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class Rc2ReadOnlySmokeScriptTest extends TestCase
{
    public function testScriptUsesOnlyRequiredReadOnlyEndpointsAndSafeOutput(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 3) . '/tools/Invoke-Rc2ReadOnlySmoke.ps1',
        );

        self::assertStringContainsString('[string]$BaseUrl', $script);
        self::assertStringContainsString("-Path '/health'", $script);
        self::assertStringContainsString("-Path '/'", $script);
        self::assertStringContainsString("-Path '/admin/login'", $script);
        self::assertStringContainsString('-Method Get', $script);
        self::assertStringContainsString("\$healthPayload.status -ne 'ok'", $script);
        self::assertStringContainsString("-Name 'Cache-Control'", $script);
        self::assertStringContainsString("-Name 'Content-Security-Policy'", $script);
        self::assertStringContainsString('exit 1', $script);

        foreach (['-Method Post', '-Method Put', '-Method Patch', '-Method Delete', '/api/bookings',
            '/calendar/export.ics', 'token=', 'Authorization', 'WebSession', 'SessionVariable'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script);
        }
        self::assertStringNotContainsString('$response.Content', $script);
        self::assertStringNotContainsString('$response.Headers', $script);
    }
}
