<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class AuthConfigurationTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'ADMIN_SESSION_IDLE_TIMEOUT_SECONDS', 'ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS'] as $name) {
            $this->original[$name] = getenv($name);
        }
        putenv('APP_ENV=development');
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testDefaultIdleIsThirtyMinutesAndTwoFactorValuesStayUnchanged(): void
    {
        putenv('ADMIN_SESSION_IDLE_TIMEOUT_SECONDS');
        putenv('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS=28800');

        $config = require dirname(__DIR__, 3) . '/config/auth.php';

        self::assertSame(1800, $config['session_idle_timeout_seconds']);
        self::assertSame(28800, $config['session_absolute_timeout_seconds']);
        self::assertSame(600, $config['two_factor_ttl_seconds']);
        self::assertSame(5, $config['two_factor_max_attempts']);
        self::assertSame(60, $config['two_factor_resend_seconds']);
    }

    public function testConfiguredIdleAtOrAboveMinimumIsAccepted(): void
    {
        putenv('ADMIN_SESSION_IDLE_TIMEOUT_SECONDS=3600');
        putenv('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS=7200');

        $config = require dirname(__DIR__, 3) . '/config/auth.php';

        self::assertSame(3600, $config['session_idle_timeout_seconds']);
        self::assertSame(7200, $config['session_absolute_timeout_seconds']);
    }

    public function testConfiguredIdleBelowThirtyMinutesFailsClosed(): void
    {
        putenv('ADMIN_SESSION_IDLE_TIMEOUT_SECONDS=1799');
        putenv('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS=28800');

        $this->expectException(\RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/auth.php';
    }

    public function testAbsoluteTimeoutMustExceedActualConfiguredIdleTimeout(): void
    {
        putenv('ADMIN_SESSION_IDLE_TIMEOUT_SECONDS=3600');
        putenv('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS=3600');

        $this->expectException(\RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/auth.php';
    }
}
