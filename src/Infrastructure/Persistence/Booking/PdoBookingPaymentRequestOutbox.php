<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\BookingPaymentRequestMailData;
use App\Application\Mail\BookingPaymentRequestOutbox;
use App\Application\Mail\PaymentReference;
use PDO;

final readonly class PdoBookingPaymentRequestOutbox implements BookingPaymentRequestOutbox
{
    public function __construct(private PDO $pdo)
    {
    }

    public function claim(string $reference, BookingPaymentRequestConfiguration $configuration): ?array
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Payment request must run outside a booking transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM bookings WHERE reference = :reference FOR UPDATE');
            $query->execute(['reference' => $reference]);
            $booking = $query->fetch(PDO::FETCH_ASSOC);
            if ($booking === false) {
                throw new \OutOfBoundsException('A foglalás nem található.');
            }
            if ($booking['status'] !== 'pending') {
                throw new \DomainException('Díjbekérő csak függő foglaláshoz küldhető.');
            }
            $query = $this->pdo->prepare("SELECT * FROM email_outbox WHERE booking_id = :booking_id AND message_type = 'booking_payment_request' FOR UPDATE");
            $query->execute(['booking_id' => $booking['id']]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $retry = $row !== false && $row['status'] === 'failed';
            if ($row !== false && !in_array($row['status'], ['pending', 'failed'], true)) {
                $this->pdo->commit();
                return null;
            }
            if ($row === false) {
                $legacy = $this->pdo->prepare('SELECT pricing_unavailable FROM legacy_booking_imports WHERE booking_id = :booking_id');
                $legacy->execute(['booking_id' => $booking['id']]);
                if ((int) $legacy->fetchColumn() === 1) {
                    throw new \InvalidArgumentException('A történeti foglalás tárolt ára hiányzik, ezért díjbekérő nem küldhető.');
                }
                $configuration->assertConfigured();
                $snapshotQuery = $this->pdo->prepare('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id = :booking_id FOR UPDATE');
                $snapshotQuery->execute(['booking_id' => $booking['id']]);
                $snapshotJson = $snapshotQuery->fetchColumn();
                if (!is_string($snapshotJson)) {
                    throw new \InvalidArgumentException('A díjbekérőhöz használható immutable pricing snapshot szükséges.');
                }
                $snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($snapshot)) {
                    throw new \InvalidArgumentException('A díjbekérő pricing snapshotja érvénytelen.');
                }
                $accommodationFee = $this->money($snapshot['accommodation_fee'] ?? null);
                $taxes = $this->money($snapshot['taxes'] ?? null);
                $total = $this->money($snapshot['total'] ?? null);
                $currency = $snapshot['currency'] ?? null;
                if ($accommodationFee === null || $taxes === null || $total === null || $currency !== 'HUF') {
                    throw new \InvalidArgumentException('A díjbekérő pricing snapshotja hiányos vagy nem támogatott.');
                }
                $data = new BookingPaymentRequestMailData($reference, $booking['guest_email'], $booking['guest_name'],
                    $booking['arrival_date'], $booking['departure_date'], $currency, $total,
                    $configuration->advancePercent, $configuration->advanceFor($accommodationFee),
                    $configuration->beneficiary, $configuration->bankAccount, $accommodationFee, $taxes,
                    PaymentReference::forBookingId((int) $booking['id']), $configuration->bankName,
                    $configuration->swiftBic, 2);
                $insert = $this->pdo->prepare("INSERT INTO email_outbox (booking_id, message_type, recipient, subject, payload)
                    VALUES (:booking_id, 'booking_payment_request', :recipient, :subject, :payload)");
                $insert->execute(['booking_id' => $booking['id'], 'recipient' => $data->recipient,
                    'subject' => $data->subject(), 'payload' => json_encode($data->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $id = (int) $row['id'];
                $data = BookingPaymentRequestMailData::fromPayload(json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR));
            }
            $claim = $this->pdo->prepare("UPDATE email_outbox SET status = 'processing' WHERE id = :id AND status IN ('pending', 'failed')");
            $claim->execute(['id' => $id]);
            $this->pdo->commit();
            return ['id' => $id, 'booking_id' => (int) $booking['id'], 'data' => $data, 'retry' => $retry];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function status(string $reference): string
    {
        $query = $this->pdo->prepare("SELECT e.status FROM email_outbox e JOIN bookings b ON b.id = e.booking_id
            WHERE b.reference = :reference AND e.message_type = 'booking_payment_request'");
        $query->execute(['reference' => $reference]);
        $status = $query->fetchColumn();
        return in_array($status, ['sent', 'failed'], true) ? $status : 'pending';
    }

    public function markSent(int $outboxId): void
    {
        $query = $this->pdo->prepare("UPDATE email_outbox SET status = 'sent', attempts = attempts + 1,
            sent_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = :id AND status = 'processing' AND message_type = 'booking_payment_request'");
        $query->execute(['id' => $outboxId]);
    }

    public function markFailed(int $outboxId, string $safeReason): void
    {
        $query = $this->pdo->prepare("UPDATE email_outbox SET status = 'failed', attempts = attempts + 1,
            sent_at = NULL, last_error = :reason WHERE id = :id AND status = 'processing' AND message_type = 'booking_payment_request'");
        $query->execute(['id' => $outboxId, 'reason' => mb_substr($safeReason, 0, 500)]);
    }

    private function money(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (preg_match('/\A\d{1,10}(?:\.\d{2})?\z/', $value) !== 1) {
            return null;
        }
        return str_contains($value, '.') ? $value : $value . '.00';
    }
}
