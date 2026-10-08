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
        self::assertSame(10, $configuration['timeout_seconds']);
        self::assertSame('none', $configuration['encryption']);
        self::assertSame('A Bata', $configuration['from_name']);
        self::assertSame('info@abata.local', $configuration['guest_reply_to_email']);
        self::assertSame('A Bata', $configuration['guest_reply_to_name']);
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
        $this->setProductionEnvironment();

        $configuration = require dirname(__DIR__, 3) . '/config/mail.php';

        self::assertTrue($configuration['production']);
        self::assertSame('tls', $configuration['encryption']);
        self::assertSame('smtp.example.test', $configuration['host']);
        self::assertSame(25, $configuration['timeout_seconds']);
        self::assertSame('A Bata', $configuration['from_name']);
        self::assertSame('info@abata.hu', $configuration['guest_reply_to_email']);
        self::assertSame('A Bata', $configuration['guest_reply_to_name']);
    }

    public function testProductionRequiresGuestReplyToEmail(): void
    {
        $this->setProductionEnvironment();
        putenv('GUEST_REPLY_TO_EMAIL');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GUEST_REPLY_TO_EMAIL is required in production.');

        require dirname(__DIR__, 3) . '/config/mail.php';
    }

    public function testInvalidGuestReplyToAddressFailsFast(): void
    {
        putenv('APP_ENV=development');
        putenv('GUEST_REPLY_TO_EMAIL=invalid-address');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GUEST_REPLY_TO_EMAIL');

        require dirname(__DIR__, 3) . '/config/mail.php';
    }

    /** @return list<string> */
    private function variableNames(): array
    {
        return [
            'APP_ENV', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_TIMEOUT_SECONDS', 'MAIL_ENCRYPTION', 'MAIL_USERNAME',
            'MAIL_PASSWORD', 'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME', 'GUEST_REPLY_TO_EMAIL', 'GUEST_REPLY_TO_NAME',
        ];
    }

    private function setProductionEnvironment(): void
    {
        putenv('APP_ENV=production');
        putenv('MAIL_HOST=smtp.example.test');
        putenv('MAIL_PORT=587');
        putenv('MAIL_TIMEOUT_SECONDS=25');
        putenv('MAIL_ENCRYPTION=tls');
        putenv('MAIL_USERNAME=deployment-user');
        putenv('MAIL_PASSWORD=deployment-secret');
        putenv('MAIL_FROM_EMAIL=no-reply@example.test');
        putenv('MAIL_FROM_NAME=A Bata');
        putenv('GUEST_REPLY_TO_EMAIL=info@abata.hu');
        putenv('GUEST_REPLY_TO_NAME=A Bata');
    }
}
