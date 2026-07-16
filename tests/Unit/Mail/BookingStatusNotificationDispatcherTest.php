<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Mail\BookingStatusMailData;
use App\Application\Mail\BookingStatusMailRenderer;
use App\Application\Mail\BookingStatusNotificationDispatcher;
use App\Application\Mail\BookingStatusNotificationOutbox;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use PHPUnit\Framework\TestCase;

final class BookingStatusNotificationDispatcherTest extends TestCase
{
    public function testSendsClaimedNotificationAndMarksLogSent(): void
    {
        $outbox = new FakeStatusOutbox();
        $mailer = new InMemoryMailer();
        $audit = new FakeStatusAuditLog();

        $result = (new BookingStatusNotificationDispatcher($outbox, $this->renderer(), $mailer, $audit))->dispatch(42, 'confirmed', 3);

        self::assertSame('sent', $result->status);
        self::assertSame([7], $outbox->sent);
        self::assertCount(1, $mailer->messages());
        self::assertSame('email.status_notification_sent', $audit->events[0]->eventType);
        self::assertSame(3, $audit->events[0]->adminId);
    }

    public function testTransportFailureMarksSafeFailureWithoutThrowing(): void
    {
        $outbox = new FakeStatusOutbox();
        $audit = new FakeStatusAuditLog();
        $mailer = new class implements Mailer {
            public function send(Message $message): void
            {
                throw new \RuntimeException('smtp://secret@example.test');
            }
        };

        $result = (new BookingStatusNotificationDispatcher($outbox, $this->renderer(), $mailer, $audit))->dispatch(42, 'rejected');

        self::assertSame('failed', $result->status);
        self::assertSame([[7, 'E-mail transport failure.']], $outbox->failed);
        self::assertSame('email.status_notification_failed', $audit->events[0]->eventType);
    }

    public function testInvalidatedNeverClaimsOrSendsGuestEmail(): void
    {
        $outbox = new FakeStatusOutbox();
        $mailer = new InMemoryMailer();

        $result = (new BookingStatusNotificationDispatcher($outbox, $this->renderer(), $mailer))->dispatch(42, 'invalidated');

        self::assertSame('not_applicable', $result->status);
        self::assertSame(0, $outbox->claims);
        self::assertCount(0, $mailer->messages());
    }

    private function renderer(): BookingStatusMailRenderer
    {
        return new BookingStatusMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'noreply@example.test');
    }
}

final class FakeStatusAuditLog implements AuditLog
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class FakeStatusOutbox implements BookingStatusNotificationOutbox
{
    public int $claims = 0;
    /** @var list<int> */
    public array $sent = [];
    /** @var list<array{int, string}> */
    public array $failed = [];

    public function findForDelivery(int $bookingId, string $status): ?array
    {
        ++$this->claims;

        return ['id' => 7, 'data' => new BookingStatusMailData(
            'guest@example.test', $status, 'AB-2026-001', '2026-08-10', '2026-08-13', 2, 1, '90000.00', 'HUF'
        )];
    }

    public function markSent(int $outboxId): void
    {
        $this->sent[] = $outboxId;
    }

    public function markFailed(int $outboxId, string $safeReason): void
    {
        $this->failed[] = [$outboxId, $safeReason];
    }

    public function status(int $bookingId, string $status): string
    {
        return 'sent';
    }
}
