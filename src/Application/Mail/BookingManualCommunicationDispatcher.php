<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;

final readonly class BookingManualCommunicationDispatcher
{
    public function __construct(
        private BookingManualCommunicationOutbox $outbox,
        private BookingManualCommunicationRenderer $renderer,
        private Mailer $mailer,
        private BookingPaymentRequestConfiguration $paymentConfiguration,
        private ?AuditLog $auditLog = null,
    ) {}

    public function paymentReminder(string $reference, ?int $adminId = null): OutboxDeliveryResult
    {
        return $this->dispatch($reference, 'booking_payment_reminder', 'payment_reminder', $adminId);
    }

    public function arrivalInformation(string $reference, ?int $adminId = null): OutboxDeliveryResult
    {
        return $this->dispatch($reference, 'booking_arrival_information', 'arrival_information', $adminId);
    }

    private function dispatch(string $reference, string $type, string $auditName, ?int $adminId): OutboxDeliveryResult
    {
        $item = $this->outbox->claim($reference, $type, $this->paymentConfiguration);
        if ($item === null) return new OutboxDeliveryResult($this->outbox->status($reference, $type));
        if ($item['retry']) $this->audit('email.' . $auditName . '_retry', 'pending', $item, $adminId);
        try {
            $message = $this->renderer->render($type, $item['payload']);
            $this->mailer->send($message);
        } catch (\Throwable) {
            $this->outbox->markFailed($item['id'], $type, 'E-mail transport or rendering failure.');
            $this->audit('email.' . $auditName . '_failed', 'failed', $item, $adminId);
            return new OutboxDeliveryResult('failed');
        }
        $this->outbox->markSent($item['id'], $type);
        $this->audit('email.' . $auditName . '_sent', 'sent', $item, $adminId);
        return new OutboxDeliveryResult('sent');
    }

    /** @param array{id:int,booking_id:int,type:string,payload:array<string,mixed>,retry:bool} $item */
    private function audit(string $type, string $result, array $item, ?int $adminId): void
    {
        $this->auditLog?->append(new AuditEvent($type, $result,
            new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest')),
            new AuditMetadata(['target_type'=>'booking','target_id'=>(string)$item['booking_id'],'outbox_id'=>$item['id']]),
            $adminId));
    }
}
