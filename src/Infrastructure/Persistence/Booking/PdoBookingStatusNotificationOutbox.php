<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\BookingStatusMailData;
use App\Application\Mail\BookingStatusNotificationOutbox;
use PDO;

final readonly class PdoBookingStatusNotificationOutbox implements BookingStatusNotificationOutbox
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findForDelivery(int $bookingId, string $status): ?array
    {
        $messageType = $this->messageType($status);
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('SMTP delivery must run after the status transaction commits.');
        }
        $claim = $this->pdo->prepare(
            "UPDATE email_outbox SET status = 'processing'
             WHERE booking_id = :booking_id AND message_type = :message_type AND status IN ('pending', 'failed')"
        );
        $claim->execute(['booking_id' => $bookingId, 'message_type' => $messageType]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, recipient, payload FROM email_outbox
             WHERE booking_id = :booking_id AND message_type = :message_type AND status = 'processing' LIMIT 1"
        );
        $statement->execute(['booking_id' => $bookingId, 'message_type' => $messageType]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return ['id' => (int) $row['id'], 'data' => new BookingStatusMailData(
            (string) $row['recipient'], $status, (string) $payload['booking_reference'],
            (string) $payload['arrival_date'], (string) $payload['departure_date'],
            (int) $payload['adults'], (int) $payload['children'],
            (string) $payload['total'], (string) $payload['currency'],
            isset($payload['cancellation_penalty_amount']) ? (string) $payload['cancellation_penalty_amount'] : null,
            isset($payload['cancellation_accommodation_fee']) ? (string) $payload['cancellation_accommodation_fee'] : null,
        )];
    }

    public function markSent(int $outboxId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE email_outbox SET status = 'sent', attempts = attempts + 1,
             last_error = NULL, sent_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $outboxId]);
    }

    public function markFailed(int $outboxId, string $safeReason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE email_outbox SET status = 'failed', attempts = attempts + 1,
             last_error = :reason, sent_at = NULL WHERE id = :id"
        );
        $statement->execute(['id' => $outboxId, 'reason' => mb_substr($safeReason, 0, 500)]);
    }

    public function status(int $bookingId, string $status): string
    {
        $statement = $this->pdo->prepare(
            'SELECT status FROM email_outbox WHERE booking_id = :booking_id AND message_type = :message_type LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId, 'message_type' => $this->messageType($status)]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : 'pending';
    }

    private function messageType(string $status): string
    {
        if (!in_array($status, ['confirmed', 'rejected', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Unsupported booking notification status.');
        }

        return 'booking_' . $status;
    }
}
