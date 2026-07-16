<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Message;
use App\Application\Mail\TwoFactorMailRenderer;
use App\Infrastructure\Mail\SmtpConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    public function testTwoFactorMailHasBothBodiesAndDoesNotExposeCodeInSubject(): void
    {
        $renderer = new TwoFactorMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'admin@abata.test');
        $message = $renderer->render('owner@example.test', '123456');

        self::assertStringNotContainsString('123456', $message->subject);
        self::assertStringContainsString('A Bata', $message->subject);
        self::assertStringContainsString('123456', $message->textBody);
        self::assertStringContainsString('10 perc', $message->textBody);
        self::assertStringContainsString('123456', $message->htmlBody);
        self::assertStringContainsString('#19194B', $message->htmlBody);
        self::assertStringContainsString('#F0A236', $message->htmlBody);
    }

    public function testInMemoryMailerCapturesMessage(): void
    {
        $mailer = new InMemoryMailer();
        $message = new Message('from@example.test', 'to@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>');
        $mailer->send($message);

        self::assertSame([$message], $mailer->messages());
        self::assertSame($message, $mailer->lastMessage());
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message('from@example.test', 'to@example.test', "Tárgy\r\nBcc: victim@example.test", 'Szöveg', '<p>HTML</p>');
    }

    public function testInvalidTwoFactorCodeIsRejected(): void
    {
        $renderer = new TwoFactorMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'admin@abata.test');
        $this->expectException(InvalidArgumentException::class);
        $renderer->render('owner@example.test', '12345');
    }

    public function testSmtpCredentialsMustBeComplete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpConfiguration('mailpit', 1025, 'none', 'user', null);
    }

    public function testSmtpAuthenticationRequiresEncryption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpConfiguration('smtp.example.test', 587, 'none', 'user', 'secret');
    }
}
