<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach ($this->variableNames() as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testDevelopmentUsesMailpitDefaults(): void
    {
        putenv('APP_ENV=development');
        $configuration = require dirname(__DIR__, 3) . '/config/mail.php';

        self::assertSame('mailpit', $configuration['host']);
        self::assertSame(1025, $configuration['port']);
        self::assertSame('none', $configuration['encryption']);
        self::assertFalse($configuration['production']);
    }

    public function testProductionDoesNotFallBackToDevelopmentSmtp(): void
    {
        putenv('APP_ENV=production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_HOST is required in production.');

        require dirname(__DIR__, 3) . '/config/mail.php';
    }

    public function testInvalidFromAddressFailsFast(): void
    {
        putenv('APP_ENV=development');
        putenv('MAIL_FROM_EMAIL=invalid-address');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_FROM_EMAIL');

        require dirname(__DIR__, 3) . '/config/mail.php';
    }

    public function testCompleteProductionConfigurationIsReturnedWithoutExposingCredential(): void
    {
        putenv('APP_ENV=production');
        putenv('MAIL_HOST=smtp.example.test');
        putenv('MAIL_PORT=587');
        putenv('MAIL_ENCRYPTION=tls');
        putenv('MAIL_USERNAME=deployment-user');
        putenv('MAIL_PASSWORD=deployment-secret');
        putenv('MAIL_FROM_EMAIL=no-reply@example.test');
        putenv('MAIL_FROM_NAME=Example Accommodation');

        $configuration = require dirname(__DIR__, 3) . '/config/mail.php';

        self::assertTrue($configuration['production']);
        self::assertSame('tls', $configuration['encryption']);
        self::assertSame('smtp.example.test', $configuration['host']);
    }

    /** @return list<string> */
    private function variableNames(): array
    {
        return [
            'APP_ENV', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME',
            'MAIL_PASSWORD', 'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME',
        ];
    }
}
