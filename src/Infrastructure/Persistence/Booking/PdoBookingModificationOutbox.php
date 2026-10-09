<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\BookingModificationMailData;
use App\Application\Mail\BookingModificationOutbox;
use PDO;

final readonly class PdoBookingModificationOutbox implements BookingModificationOutbox
{
    public function __construct(private PDO $pdo) {}

    public function findForDelivery(int $modificationId): ?array
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('SMTP delivery must run after commit.');
        $key = 'modification:' . $modificationId;
        $claim = $this->pdo->prepare(
            "UPDATE email_outbox SET status = 'processing'
             WHERE message_type = 'booking_modified' AND deduplication_key = :deduplication_key
               AND status IN ('pending', 'failed')"
        );
        $claim->execute(['deduplication_key' => $key]);
        if ($claim->rowCount() !== 1) return null;
        $statement = $this->pdo->prepare(
            "SELECT id, booking_id, recipient, payload FROM email_outbox
             WHERE message_type = 'booking_modified' AND deduplication_key = :deduplication_key
               AND status = 'processing' LIMIT 1"
        );
        $statement->execute(['deduplication_key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return ['id' => (int) $row['id'], 'booking_id' => (int) $row['booking_id'], 'data' => new BookingModificationMailData(
            (string) $row['recipient'], (string) $payload['contact_name'], (string) $payload['booking_reference'],
            (string) $payload['arrival_date'], (string) $payload['departure_date'], (int) $payload['nights'],
            (int) $payload['adults'], array_map('intval', $payload['child_ages'] ?? []),
            (string) $payload['accommodation_fee'], (string) $payload['tourism_tax'], (string) $payload['total_amount'],
            isset($payload['unchanged_deposit_amount']) ? (string) $payload['unchanged_deposit_amount'] : null,
            (string) $payload['currency'],
        )];
    }

    public function markSent(int $outboxId): void
    {
        $statement = $this->pdo->prepare("UPDATE email_outbox SET status='sent', attempts=attempts+1, last_error=NULL, sent_at=CURRENT_TIMESTAMP WHERE id=:id AND status='processing'");
        $statement->execute(['id' => $outboxId]);
    }

    public function markFailed(int $outboxId, string $safeReason): void
    {
        $statement = $this->pdo->prepare("UPDATE email_outbox SET status='failed', attempts=attempts+1, last_error=:reason, sent_at=NULL WHERE id=:id AND status='processing'");
        $statement->execute(['id' => $outboxId, 'reason' => mb_substr($safeReason, 0, 500)]);
    }

    public function status(int $modificationId): string
    {
        $statement = $this->pdo->prepare("SELECT status FROM email_outbox WHERE message_type='booking_modified' AND deduplication_key=:deduplication_key LIMIT 1");
        $statement->execute(['deduplication_key' => 'modification:' . $modificationId]);
        $status = $statement->fetchColumn();
        return is_string($status) ? $status : 'pending';
    }
}
