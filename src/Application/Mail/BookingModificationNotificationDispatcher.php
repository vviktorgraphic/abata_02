<?php
declare(strict_types=1);
namespace App\Application\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class BookingModificationNotificationDispatcher
{
    public function __construct(
        private BookingModificationOutbox $outbox,
        private BookingModificationMailRenderer $renderer,
        private Mailer $mailer,
        private ?AuditLog $audit = null,
    ) {}

    public function dispatch(int $modificationId, ?int $adminId = null): OutboxDeliveryResult
    {
        $item = $this->outbox->findForDelivery($modificationId);
        if ($item === null) return new OutboxDeliveryResult($this->outbox->status($modificationId));
        try {
            $this->mailer->send($this->renderer->render($item['data']));
        } catch (Throwable) {
            $this->outbox->markFailed($item['id'], 'E-mail transport failure.');
            $this->audit('email.booking_modified_failed', 'failed', $item['booking_id'], $item['id'], $modificationId, $adminId);
            return new OutboxDeliveryResult('failed');
        }
        $this->outbox->markSent($item['id']);
        $this->audit('email.booking_modified_sent', 'sent', $item['booking_id'], $item['id'], $modificationId, $adminId);
        return new OutboxDeliveryResult('sent');
    }

    private function audit(string $event, string $outcome, int $bookingId, int $outboxId, int $modificationId, ?int $adminId): void
    {
        $this->audit?->append(new AuditEvent($event, $outcome,
            new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')),
            new AuditMetadata(['target_type'=>'booking','target_id'=>(string)$bookingId,'outbox_id'=>$outboxId,'modification_id'=>$modificationId]),
            $adminId));
    }
}
