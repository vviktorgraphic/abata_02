<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\BookingRequestMailData;
use App\Application\Mail\BookingRequestMailRenderer;
use PHPUnit\Framework\TestCase;

final class BookingRequestMailRendererTest extends TestCase
{
    public function testRendersRequiredPendingRequestDetailsAndBrandTokens(): void
    {
        $renderer = new BookingRequestMailRenderer(
            dirname(__DIR__, 3) . '/templates/email',
            'no-reply@example.test',
        );
        $message = $renderer->render(new BookingRequestMailData(
            'guest@example.test', 'AB-123', '2027-08-10', '2027-08-13', 2, [6], '45000.00', 'HUF',
        ));

        self::assertSame('guest@example.test', $message->to);
        self::assertStringContainsString('A Bata', $message->textBody);
        self::assertStringContainsString('AB-123', $message->textBody);
        self::assertStringContainsString('Éjszakák: 3', $message->textBody);
        self::assertStringContainsString('Gyermekek: 1', $message->textBody);
        self::assertStringContainsString('45000.00 HUF', $message->textBody);
        self::assertStringContainsString('nem visszaigazolt foglalás', $message->textBody);
        self::assertStringContainsString('admin jóváhagyása után', $message->htmlBody);
        self::assertStringContainsString('#19194B', $message->htmlBody);
        self::assertStringContainsString('#F0A236', $message->htmlBody);
        self::assertStringContainsString('#FFFFFF', $message->htmlBody);
    }

    public function testEscapesUserControlledReferenceInHtml(): void
    {
        $renderer = new BookingRequestMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'sender@example.test');
        $message = $renderer->render(new BookingRequestMailData(
            'guest@example.test', '<script>alert(1)</script>', '2027-08-10', '2027-08-11', 1, [], '10000.00', 'HUF',
        ));

        self::assertStringNotContainsString('<script>', $message->htmlBody);
        self::assertStringContainsString('&lt;script&gt;', $message->htmlBody);
    }
}
