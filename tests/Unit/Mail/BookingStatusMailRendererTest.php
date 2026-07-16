<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\BookingStatusMailData;
use App\Application\Mail\BookingStatusMailRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingStatusMailRendererTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function statuses(): iterable
    {
        yield 'confirmed' => ['confirmed'];
        yield 'rejected' => ['rejected'];
        yield 'cancelled' => ['cancelled'];
    }

    #[DataProvider('statuses')]
    public function testRendersVersionedHtmlAndPlainNotification(string $status): void
    {
        $message = $this->renderer()->render($this->data($status));

        self::assertStringContainsString('A Bata', $message->subject);
        self::assertStringContainsString('AB-2026-001', $message->subject);
        self::assertStringContainsString('AB-2026-001', $message->textBody);
        self::assertStringContainsString('AB-2026-001', $message->htmlBody);
        self::assertStringContainsString('A Bata', $message->htmlBody);
    }

    public function testConfirmedContainsDatesGuestsAndAmount(): void
    {
        $message = $this->renderer()->render($this->data('confirmed'));

        foreach (['2026-08-10', '2026-08-13', '2 felnőtt', '1 gyermek', '90000.00 HUF'] as $value) {
            self::assertStringContainsString($value, $message->textBody);
        }
    }

    public function testRejectedDoesNotContainInternalNoteOrUnneededBookingDetails(): void
    {
        $message = $this->renderer()->render($this->data('rejected'));

        self::assertStringNotContainsString('belső admin', $message->textBody . $message->htmlBody);
        self::assertStringNotContainsString('90000.00', $message->textBody . $message->htmlBody);
    }

    public function testCancelledContainsRelevantDates(): void
    {
        $message = $this->renderer()->render($this->data('cancelled'));

        self::assertStringContainsString('2026-08-10', $message->textBody);
        self::assertStringContainsString('2026-08-13', $message->textBody);
        self::assertStringContainsString('Lemondási kötbér: 45000.00 HUF', $message->textBody);
        self::assertStringContainsString('automatikus terhelés nem történt', $message->textBody);
    }

    public function testRejectsInvalidatedNotificationConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->data('invalidated');
    }

    private function renderer(): BookingStatusMailRenderer
    {
        return new BookingStatusMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'noreply@example.test');
    }

    private function data(string $status): BookingStatusMailData
    {
        return new BookingStatusMailData(
            'guest@example.test', $status, 'AB-2026-001', '2026-08-10', '2026-08-13',
            2, 1, '90000.00', 'HUF',
            $status === 'cancelled' ? '45000.00' : null,
            $status === 'cancelled' ? '90000.00' : null,
        );
    }
}
