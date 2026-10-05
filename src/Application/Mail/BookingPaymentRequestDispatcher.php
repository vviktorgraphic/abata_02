<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;

final readonly class BookingPaymentRequestDispatcher
{
    public function __construct(
        private BookingPaymentRequestOutbox $outbox,
        private BookingPaymentRequestMailRenderer $renderer,
        private Mailer $mailer,
        private BookingPaymentRequestConfiguration $configuration,
        private ?AuditLog $auditLog = null,
    ) {
    }

    public function dispatch(string $reference, ?int $adminId = null): OutboxDeliveryResult
    {
        $item = $this->outbox->claim($reference, $this->configuration);
        if ($item === null) {
            return new OutboxDeliveryResult($this->outbox->status($reference));
        }
        if ($item['retry']) {
            $this->audit('email.payment_request_retry', 'pending', $item, $adminId);
        }
        try {
            $this->mailer->send($this->renderer->render($item['data']));
        } catch (\Throwable) {
            $this->outbox->markFailed($item['id'], 'E-mail transport failure.');
            $this->audit('email.payment_request_failed', 'failed', $item, $adminId);
            return new OutboxDeliveryResult('failed');
        }
        // Persistence/audit failure after SMTP acceptance must never make the message retryable.
        $this->outbox->markSent($item['id']);
        $this->audit('email.payment_request_sent', 'sent', $item, $adminId);
        return new OutboxDeliveryResult('sent');
    }

    /** @param array{id: int, booking_id: int, data: BookingPaymentRequestMailData, retry: bool} $item */
    private function audit(string $type, string $result, array $item, ?int $adminId): void
    {
        $this->auditLog?->append(new AuditEvent($type, $result,
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest')),
            new AuditMetadata(['target_type' => 'booking', 'target_id' => (string) $item['booking_id'], 'outbox_id' => $item['id']]), $adminId));
    }
}
