<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\RequestTransportSecurity;
use PHPUnit\Framework\TestCase;

final class RequestTransportSecurityTest extends TestCase
{
    public function testDirectHttpsIsRecognized(): void
    {
        self::assertTrue((new RequestTransportSecurity())->isHttps(['HTTPS' => 'on']));
        self::assertTrue((new RequestTransportSecurity())->isHttps(['SERVER_PORT' => 443]));
    }

    public function testForwardedProtoIsIgnoredFromUntrustedClient(): void
    {
        self::assertFalse((new RequestTransportSecurity())->isHttps([
            'REMOTE_ADDR' => '203.0.113.8',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
    }

    public function testForwardedProtoIsAcceptedOnlyFromExplicitTrustedProxy(): void
    {
        $security = new RequestTransportSecurity(['10.0.0.5']);
        self::assertTrue($security->isHttps([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ]));
        self::assertFalse($security->isHttps([
            'REMOTE_ADDR' => '10.0.0.6',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
    }
}
