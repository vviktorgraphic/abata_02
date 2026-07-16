<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\BookingRequestMailData;
use App\Application\Mail\BookingRequestOutbox;
use PDO;

final readonly class PdoBookingRequestOutbox implements BookingRequestOutbox
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findForDelivery(int $bookingId): ?array
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('SMTP delivery must run after the booking transaction commits.');
        }

        // Atomic claim: automatic retry is deliberately not part of this sprint, so
        // only a never-attempted pending item may transition to processing.
        $claim = $this->pdo->prepare(
            'UPDATE email_outbox SET status = \'processing\'
             WHERE booking_id = :booking_id AND message_type = :message_type AND status = \'pending\''
        );
        $claim->execute(['booking_id' => $bookingId, 'message_type' => 'booking_request_received']);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, recipient, payload FROM email_outbox
             WHERE booking_id = :booking_id AND message_type = :message_type AND status = \'processing\'
             LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId, 'message_type' => 'booking_request_received']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return ['id' => (int) $row['id'], 'data' => new BookingRequestMailData(
            (string) $row['recipient'], (string) $payload['booking_reference'],
            (string) $payload['arrival_date'], (string) $payload['departure_date'],
            (int) $payload['adults'], array_map('intval', $payload['child_ages']),
            (string) $payload['total'], (string) $payload['currency'],
        )];
    }

    public function markSent(int $outboxId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE email_outbox SET status = \'sent\', attempts = attempts + 1,
             last_error = NULL, sent_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $outboxId]);
    }

    public function markFailed(int $outboxId, string $safeReason): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE email_outbox SET status = \'failed\', attempts = attempts + 1,
             last_error = :reason, sent_at = NULL WHERE id = :id'
        );
        $statement->execute(['id' => $outboxId, 'reason' => mb_substr($safeReason, 0, 500)]);
    }

    public function statusForBooking(int $bookingId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT status FROM email_outbox WHERE booking_id = :booking_id AND message_type = :message_type LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId, 'message_type' => 'booking_request_received']);
        $status = $statement->fetchColumn();

        // processing is intentionally not exposed through the public booking API.
        return $status === 'processing' ? 'pending' : (is_string($status) ? $status : 'pending');
    }
}
