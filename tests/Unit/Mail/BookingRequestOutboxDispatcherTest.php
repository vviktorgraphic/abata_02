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

    private function renderer(): BookingRequestMailRenderer
    {
        return new BookingRequestMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'sender@example.test');
    }
}

final class FakeBookingRequestOutbox implements BookingRequestOutbox
{
    public bool $deliverable = true;
    public string $status = 'pending';
    /** @var list<int> */ public array $sent = [];
    /** @var list<array{int, string}> */ public array $failed = [];
    public int $bookingStillExists = 42;

    public function findForDelivery(int $bookingId): ?array
    {
        if (!$this->deliverable) { return null; }
        return ['id' => 7, 'data' => new BookingRequestMailData(
            'guest@example.test', 'AB-42', '2027-08-10', '2027-08-13', 2, [6], '45000.00', 'HUF',
        )];
    }
    public function markSent(int $outboxId): void { $this->sent[] = $outboxId; $this->status = 'sent'; }
    public function markFailed(int $outboxId, string $safeReason): void { $this->failed[] = [$outboxId, $safeReason]; $this->status = 'failed'; }
    public function statusForBooking(int $bookingId): string { return $this->status; }
}
