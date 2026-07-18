<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controller\HomeController;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    protected function tearDown(): void
    {
        http_response_code(200);
    }

    public function testReadyResponseContainsNoOperationalDetails(): void
    {
        ob_start();
        (new HomeController(dirname(__DIR__, 2) . '/templates', readinessCheck: static fn (): bool => true))->health();
        $body = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame(['status' => 'ok'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDependencyFailureIsGenericServiceUnavailable(): void
    {
        ob_start();
        (new HomeController(dirname(__DIR__, 2) . '/templates', readinessCheck: static function (): bool {
            throw new \PDOException('secret-host.example:3306');
        }))->health();
        $body = (string) ob_get_clean();

        self::assertSame(503, http_response_code());
        self::assertSame(['status' => 'unavailable'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-host', $body);
    }
}
