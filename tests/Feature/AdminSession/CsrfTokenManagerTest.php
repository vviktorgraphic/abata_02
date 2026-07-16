<?php

declare(strict_types=1);

namespace Tests\Feature\AdminSession;

use App\Security\Csrf\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    public function testTokenIsSecureLengthAndStableWithinSession(): void
    {
        $storage = new InMemorySessionStorage();
        $csrf = new CsrfTokenManager($storage);

        $token = $csrf->token();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->isValid($token));
    }

    public function testMissingNonStringAndMismatchedTokensAreRejected(): void
    {
        $csrf = new CsrfTokenManager(new InMemorySessionStorage());
        $csrf->token();

        self::assertFalse($csrf->isValid(null));
        self::assertFalse($csrf->isValid(['not' => 'a string']));
        self::assertFalse($csrf->isValid(str_repeat('0', 64)));
    }

    public function testRotationInvalidatesPreviousToken(): void
    {
        $csrf = new CsrfTokenManager(new InMemorySessionStorage());
        $oldToken = $csrf->token();

        $newToken = $csrf->rotate();

        self::assertNotSame($oldToken, $newToken);
        self::assertFalse($csrf->isValid($oldToken));
        self::assertTrue($csrf->isValid($newToken));
    }
}
