<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\BookingManualCommunicationOutbox;
use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\BookingPaymentRequestMailData;
use PDO;

final readonly class PdoBookingManualCommunicationOutbox implements BookingManualCommunicationOutbox
{
    private const TYPES = ['booking_payment_reminder', 'booking_arrival_information'];
    public function __construct(private PDO $pdo) {}

    public function claim(string $reference, string $type, BookingPaymentRequestConfiguration $configuration): ?array
    {
        $this->assertType($type);
        if ($this->pdo->inTransaction()) throw new \LogicException('Manual communication owns its transaction.');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT id,status,guest_name,guest_email FROM bookings WHERE reference=:reference FOR UPDATE');
            $statement->execute(['reference'=>$reference]);
            $booking = $statement->fetch(PDO::FETCH_ASSOC);
            if ($booking === false) throw new \OutOfBoundsException('A foglalás nem található.');
            if ($type === 'booking_payment_reminder' && $booking['status'] !== 'pending') {
                throw new \DomainException('Emlékeztető csak függőben lévő foglaláshoz küldhető.');
            }
            if ($type === 'booking_arrival_information' && $booking['status'] !== 'confirmed') {
                throw new \DomainException('Érkezési tájékoztató csak megerősített foglaláshoz küldhető.');
            }
            $existing = $this->pdo->prepare('SELECT * FROM email_outbox WHERE booking_id=:booking_id AND message_type=:type FOR UPDATE');
            $existing->execute(['booking_id'=>$booking['id'],'type'=>$type]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && !in_array($row['status'], ['pending','failed'], true)) {
                $this->pdo->commit();
                return null;
            }
            $retry = $row !== false && $row['status'] === 'failed';
            if ($row === false) {
                $payload = $type === 'booking_payment_reminder'
                    ? $this->reminderPayload((int)$booking['id'], $configuration)
                    : ['recipient'=>(string)$booking['guest_email'],'contact_name'=>(string)$booking['guest_name'],'template_version'=>1];
                $subject = $type === 'booking_payment_reminder' ? 'Emlékeztető – foglalási előleg' : 'Érkezési tájékoztató – A Bata';
                $insert = $this->pdo->prepare('INSERT INTO email_outbox (booking_id,message_type,recipient,subject,payload) VALUES (:booking_id,:type,:recipient,:subject,:payload)');
                $insert->execute(['booking_id'=>$booking['id'],'type'=>$type,'recipient'=>$booking['guest_email'],'subject'=>$subject,
                    'payload'=>json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                $id = (int)$this->pdo->lastInsertId();
            } else {
                $id = (int)$row['id'];
                $payload = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
            }
            $claim = $this->pdo->prepare("UPDATE email_outbox SET status='processing' WHERE id=:id AND status IN ('pending','failed')");
            $claim->execute(['id'=>$id]);
            $this->pdo->commit();
            return ['id'=>$id,'booking_id'=>(int)$booking['id'],'type'=>$type,'payload'=>$payload,'retry'=>$retry];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function status(string $reference, string $type): string
    {
        $this->assertType($type);
        $q=$this->pdo->prepare('SELECT e.status FROM email_outbox e JOIN bookings b ON b.id=e.booking_id WHERE b.reference=:reference AND e.message_type=:type');
        $q->execute(['reference'=>$reference,'type'=>$type]);
        $status=$q->fetchColumn();
        return is_string($status) ? $status : 'pending';
    }

    public function markSent(int $outboxId, string $type): void
    {
        $this->assertType($type);
        $q=$this->pdo->prepare("UPDATE email_outbox SET status='sent',attempts=attempts+1,sent_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id=:id AND message_type=:type AND status='processing'");
        $q->execute(['id'=>$outboxId,'type'=>$type]);
    }

    public function markFailed(int $outboxId, string $type, string $safeReason): void
    {
        $this->assertType($type);
        $q=$this->pdo->prepare("UPDATE email_outbox SET status='failed',attempts=attempts+1,sent_at=NULL,last_error=:reason WHERE id=:id AND message_type=:type AND status='processing'");
        $q->execute(['id'=>$outboxId,'type'=>$type,'reason'=>mb_substr($safeReason,0,500)]);
    }

    /** @return array<string,mixed> */
    private function reminderPayload(int $bookingId, BookingPaymentRequestConfiguration $configuration): array
    {
        $q=$this->pdo->prepare("SELECT status,payload FROM email_outbox WHERE booking_id=:id AND message_type='booking_payment_request' FOR UPDATE");
        $q->execute(['id'=>$bookingId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['status'] !== 'sent') {
            throw new \DomainException('Emlékeztető csak sikeresen elküldött díjbekérő után küldhető.');
        }
        $payment=BookingPaymentRequestMailData::fromPayload(json_decode((string)$row['payload'],true,512,JSON_THROW_ON_ERROR));
        if ($payment->bankName === '' || $payment->swiftBic === '') {
            $configuration->assertConfigured();
        }
        return ['recipient'=>$payment->recipient,'contact_name'=>$payment->contactName,'advance_amount'=>$payment->advanceAmount,
            'payment_reference'=>$payment->paymentReference ?? $payment->reference,'beneficiary'=>$payment->beneficiary,
            'bank_account'=>$payment->bankAccount,'bank_name'=>$payment->bankName !== '' ? $payment->bankName : $configuration->bankName,
            'swift_bic'=>$payment->swiftBic !== '' ? $payment->swiftBic : $configuration->swiftBic,'payment_template_version'=>$payment->templateVersion,'template_version'=>1];
    }

    private function assertType(string $type): void
    {
        if (!in_array($type,self::TYPES,true)) throw new \InvalidArgumentException('Nem támogatott vendégkommunikáció.');
    }
}
