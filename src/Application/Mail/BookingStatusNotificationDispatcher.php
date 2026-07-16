<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class BookingStatusNotificationDispatcher
{
    public function __construct(
        private BookingStatusNotificationOutbox $outbox,
        private BookingStatusMailRenderer $renderer,
        private Mailer $mailer,
        private ?AuditLog $auditLog = null,
    ) {
    }

    public function dispatch(int $bookingId, string $status, ?int $adminId = null): OutboxDeliveryResult
    {
        if ($status === 'invalidated') {
            return new OutboxDeliveryResult('not_applicable');
        }

        $item = $this->outbox->findForDelivery($bookingId, $status);
        if ($item === null) {
            return new OutboxDeliveryResult($this->outbox->status($bookingId, $status));
        }

        try {
            $this->mailer->send($this->renderer->render($item['data']));
            $this->outbox->markSent($item['id']);
            $this->audit('email.status_notification_sent', 'sent', $bookingId, $item['id'], $adminId);

            return new OutboxDeliveryResult('sent');
        } catch (Throwable) {
            $this->outbox->markFailed($item['id'], 'E-mail transport failure.');
            $this->audit('email.status_notification_failed', 'failed', $bookingId, $item['id'], $adminId);

            return new OutboxDeliveryResult('failed');
        }
    }

    private function audit(string $eventType, string $result, int $bookingId, int $outboxId, ?int $adminId): void
    {
        $this->auditLog?->append(new AuditEvent(
            $eventType,
            $result,
            new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')),
            new AuditMetadata(['target_type' => 'booking', 'target_id' => (string) $bookingId, 'outbox_id' => $outboxId]),
            $adminId,
        ));
    }
}
