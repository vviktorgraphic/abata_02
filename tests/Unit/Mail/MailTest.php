<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Message;
use App\Application\Mail\InlineAttachment;
use App\Application\Mail\TwoFactorMailRenderer;
use App\Infrastructure\Mail\SmtpConfiguration;
use App\Infrastructure\Mail\SmtpMailer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    public function testTwoFactorMailHasBothBodiesAndDoesNotExposeCodeInSubject(): void
    {
        $renderer = new TwoFactorMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'admin@abata.test', 'A Bata');
        $message = $renderer->render('owner@example.test', '123456');

        self::assertStringNotContainsString('123456', $message->subject);
        self::assertStringContainsString('A Bata', $message->subject);
        self::assertStringContainsString('123456', $message->textBody);
        self::assertStringContainsString('10 perc', $message->textBody);
        self::assertStringContainsString('123456', $message->htmlBody);
        self::assertStringContainsString('#19194B', $message->htmlBody);
        self::assertStringContainsString('#F0A236', $message->htmlBody);
        self::assertSame('A Bata', $message->fromName);
        self::assertNull($message->replyToEmail);
        self::assertStringContainsString('From: A Bata <admin@abata.test>', $this->mime($message));
        self::assertStringNotContainsString("\r\nReply-To:", $this->mime($message));
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

    public function testMessageAcceptsValidatedSenderAndReplyToIdentity(): void
    {
        $message = new Message(
            'from@example.test', 'to@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>',
            fromName: 'A Bata', replyToEmail: 'info@abata.test', replyToName: 'A Bata',
        );

        self::assertSame('A Bata', $message->fromName);
        self::assertSame('info@abata.test', $message->replyToEmail);
        self::assertSame('A Bata', $message->replyToName);
    }

    public function testSenderNameRejectsHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message(
            'from@example.test', 'to@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>',
            fromName: "A Bata\r\nBcc: victim@example.test",
        );
    }

    public function testReplyToRejectsInvalidAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message(
            'from@example.test', 'to@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>',
            replyToEmail: 'not-an-email', replyToName: 'A Bata',
        );
    }

    public function testReplyToNameRejectsHeaderInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message(
            'from@example.test', 'to@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>',
            replyToEmail: 'info@abata.test', replyToName: "A Bata\nCc: victim@example.test",
        );
    }

    public function testRawMimeUsesDisplayNameAndGuestReplyToWithoutChangingAddresses(): void
    {
        $message = new Message(
            'sender@example.test', 'guest@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>',
            fromName: 'A Bata', replyToEmail: 'info@abata.test', replyToName: 'A Bata',
        );
        $mime = $this->mime($message);

        self::assertStringContainsString("\r\nFrom: A Bata <sender@example.test>\r\n", "\r\n" . $mime);
        self::assertStringContainsString("\r\nReply-To: A Bata <info@abata.test>\r\n", "\r\n" . $mime);
        self::assertStringContainsString("\r\nTo: <guest@example.test>\r\n", "\r\n" . $mime);
    }

    public function testRawMimeEncodesNonAsciiDisplayName(): void
    {
        $mime = $this->mime(new Message(
            'sender@example.test', 'guest@example.test', 'Teszt', 'Szöveg', '<p>HTML</p>',
            fromName: 'Árvíztűrő Tükörfúrógép',
        ));

        self::assertStringContainsString('From: =?UTF-8?B?', $mime);
        self::assertStringContainsString(' <sender@example.test>', $mime);
    }

    public function testInlineAttachmentValidationAndRelatedMimeStructure(): void
    {
        $jpeg="\xFF\xD8safe-jpeg\xFF\xD9";
        $attachment=new InlineAttachment('arrival-test','test.jpg','image/jpeg',$jpeg);
        $message=new Message('from@example.test','to@example.test','Érkezés','Plain fallback','<p><img src="cid:arrival-test"></p>',[$attachment]);
        $mailer=new SmtpMailer(new SmtpConfiguration('mailpit',1025,'none'));
        $method=new \ReflectionMethod($mailer,'mimeMessage');
        $mime=(string)$method->invoke($mailer,$message);
        self::assertStringContainsString('multipart/related',$mime);
        self::assertStringContainsString('multipart/alternative',$mime);
        self::assertStringContainsString('Content-ID: <arrival-test>',$mime);
        self::assertStringContainsString('Content-Type: image/jpeg; name="test.jpg"',$mime);
        self::assertStringContainsString(chunk_split(base64_encode($jpeg),76,"\r\n"),$mime);

        foreach ([
            fn()=>new InlineAttachment("bad\r\n",'test.jpg','image/jpeg',$jpeg),
            fn()=>new InlineAttachment('ok','../test.jpg','image/jpeg',$jpeg),
            fn()=>new InlineAttachment('ok','test.png','image/png',$jpeg),
        ] as $invalid) {
            try { $invalid(); self::fail('Unsafe inline attachment accepted.'); } catch (InvalidArgumentException) {}
        }
    }

    public function testInvalidTwoFactorCodeIsRejected(): void
    {
        $renderer = new TwoFactorMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'admin@abata.test', 'A Bata');
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

    public function testProductionSmtpRequiresAuthentication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpConfiguration('smtp.example.test', 587, 'tls', production: true);
    }

    public function testProductionSmtpRequiresTls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpConfiguration('smtp.example.test', 25, 'none', 'user', 'secret', production: true);
    }

    public function testProductionAuthenticatedTlsConfigurationIsAccepted(): void
    {
        $configuration = new SmtpConfiguration('smtp.example.test', 587, 'tls', 'user', 'secret', production: true);
        self::assertTrue($configuration->production);
    }

    /** @dataProvider invalidSmtpHosts */
    public function testSmtpHostRejectsUrisPathsAndWhitespace(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpConfiguration($host, 587, 'tls', 'user', 'secret');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSmtpHosts(): iterable
    {
        yield 'URI' => ['smtp://example.test'];
        yield 'path' => ['example.test/smtp'];
        yield 'space' => ['smtp example.test'];
        yield 'credentials' => ['user@example.test'];
    }

    private function mime(Message $message): string
    {
        $mailer = new SmtpMailer(new SmtpConfiguration('mailpit', 1025, 'none'));
        $method = new \ReflectionMethod($mailer, 'mimeMessage');

        return (string) $method->invoke($mailer, $message);
    }
}
