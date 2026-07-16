<?php

declare(strict_types=1);

namespace Tests\Feature\AdminSession;

use App\Security\Session\SessionCookieOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionCookieOptionsTest extends TestCase
{
    public function testSecureHttpOnlyAndSameSiteOptionsAreConfigurable(): void
    {
        $options = new SessionCookieOptions(secure: true, httpOnly: true, sameSite: 'Lax');

        self::assertSame([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $options->toPhpOptions());
    }

    public function testSameSiteNoneCannotBeUsedWithoutSecure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionCookieOptions(secure: false, sameSite: 'None');
    }
}
