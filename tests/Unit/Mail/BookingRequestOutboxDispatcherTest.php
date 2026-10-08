<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\BookingRequestMailData;
use App\Application\Mail\BookingRequestMailRenderer;
use App\Application\Mail\BookingRequestOutbox;
use App\Application\Mail\BookingRequestOutboxDispatcher;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use PHPUnit\Framework\TestCase;

final class BookingRequestOutboxDispatcherTest extends TestCase
{
    public function testSendsAndMarksOutboxAfterBookingCommit(): void
    {
        $outbox = new FakeBookingRequestOutbox();
        $mailer = new InMemoryMailer();
        $result = (new BookingRequestOutboxDispatcher($outbox, $this->renderer(), $mailer))->dispatchForBooking(42);

        self::assertSame('sent', $result->status);
        self::assertSame([7], $outbox->sent);
        self::assertCount(1, $mailer->messages());
    }

    public function testTransportFailureKeepsBookingAndStoresOnlyRedactedReason(): void
    {
        $outbox = new FakeBookingRequestOutbox();
        $mailer = new class implements Mailer {
            public function send(Message $message): void
            {
                throw new \RuntimeException('smtp://secret-user:secret-pass@private-host guest@example.test');
            }
        };
        $result = (new BookingRequestOutboxDispatcher($outbox, $this->renderer(), $mailer))->dispatchForBooking(42);

        self::assertSame('failed', $result->status);
        self::assertSame([[7, 'E-mail transport failure.']], $outbox->failed);
        self::assertSame(42, $outbox->bookingStillExists);
        self::assertStringNotContainsString('secret', $outbox->failed[0][1]);
    }

    public function testSentReplayDoesNotSendAgain(): void
    {
        $outbox = new FakeBookingRequestOutbox();
        $outbox->deliverable = false;
        $outbox->status = 'sent';
        $mailer = new InMemoryMailer();
        $result = (new BookingRequestOutboxDispatcher($outbox, $this->renderer(), $mailer))->dispatchForBooking(42);

        self::assertSame('sent', $result->status);
        self::assertCount(0, $mailer->messages());
    }

    public function test_all_admin_recipients_are_attempted_independently_and_guest_status_is_preserved(): void
    {
        $outbox = new FakeBookingRequestOutbox();
        $outbox->adminDeliveries = [
            ['id' => 8, 'data' => $this->mailData('admin1@example.test', 'booking_request_admin_notification')],
            ['id' => 9, 'data' => $this->mailData('broken@example.test', 'booking_request_admin_notification')],
            ['id' => 10, 'data' => $this->mailData('admin2@example.test', 'booking_request_admin_notification')],
        ];
        $mailer = new class implements Mailer {
            /** @var list<string> */ public array $attempted = [];
            public function send(Message $message): void
            {
                $this->attempted[] = $message->to;
                if ($message->to === 'broken@example.test') throw new \RuntimeException('transport failed');
            }
        };

        $result = (new BookingRequestOutboxDispatcher($outbox, $this->renderer(), $mailer))->dispatchForBooking(42);

        self::assertSame('sent', $result->status);
        self::assertSame(['guest@example.test', 'admin1@example.test', 'broken@example.test', 'admin2@example.test'], $mailer->attempted);
        self::assertSame([7, 8, 10], $outbox->sent);
        self::assertSame([[9, 'E-mail transport failure.']], $outbox->failed);
    }

    private function mailData(string $recipient, string $messageType): BookingRequestMailData
    {
        return new BookingRequestMailData(
            $recipient, 'AB-42', '2027-08-10', '2027-08-13', 2, [6], '45000.00', 'HUF',
            messageType: $messageType,
        );
    }

    private function renderer(): BookingRequestMailRenderer
    {
        return new BookingRequestMailRenderer(
            dirname(__DIR__, 3) . '/templates/email', 'sender@example.test', 'A Bata',
            'info@abata.test', 'A Bata',
        );
    }
}

final class FakeBookingRequestOutbox implements BookingRequestOutbox
{
    public bool $deliverable = true;
    public string $status = 'pending';
    /** @var list<int> */ public array $sent = [];
    /** @var list<array{int, string}> */ public array $failed = [];
    public int $bookingStillExists = 42;
    /** @var list<array{id:int,data:BookingRequestMailData}> */ public array $adminDeliveries = [];

    public function findForDelivery(int $bookingId, string $messageType = 'booking_request_received'): ?array
    {
        if ($messageType === 'booking_request_admin_notification') {
            return array_shift($this->adminDeliveries);
        }
        if (!$this->deliverable) { return null; }
        return ['id' => 7, 'data' => new BookingRequestMailData(
            'guest@example.test', 'AB-42', '2027-08-10', '2027-08-13', 2, [6], '45000.00', 'HUF',
        )];
    }
    public function markSent(int $outboxId): void { $this->sent[] = $outboxId; if ($outboxId === 7) $this->status = 'sent'; }
    public function markFailed(int $outboxId, string $safeReason): void { $this->failed[] = [$outboxId, $safeReason]; if ($outboxId === 7) $this->status = 'failed'; }
    public function statusForBooking(int $bookingId): string { return $this->status; }
}
