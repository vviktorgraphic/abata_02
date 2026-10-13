<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\BookingModificationMailData;
use App\Application\Mail\BookingModificationOutbox;
use App\Application\Mail\EmailOutboxRetryPolicy;
use PDO;
use Throwable;

final readonly class PdoBookingModificationOutbox implements BookingModificationOutbox
{
    public function __construct(private PDO $pdo, private EmailOutboxRetryPolicy $retryPolicy = new EmailOutboxRetryPolicy()) {}

    public function claimForManualDelivery(string $bookingReference, int $modificationId): ?array
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('SMTP delivery must run after commit.');
        $this->pdo->beginTransaction();
        try {
            $modification = $this->pdo->prepare(
                'SELECT m.id, m.booking_id, m.version, m.after_snapshot, m.unchanged_deposit_amount, m.currency,
                        b.reference, b.guest_name, b.guest_email
                 FROM booking_modifications m
                 INNER JOIN bookings b ON b.id = m.booking_id
                 WHERE m.id = :modification_id AND b.reference = :reference
                 FOR UPDATE'
            );
            $modification->execute(['modification_id' => $modificationId, 'reference' => $bookingReference]);
            $row = $modification->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new \OutOfBoundsException('A módosítás nem található.');
            }

            $latest = $this->pdo->prepare(
                'SELECT id FROM booking_modifications
                 WHERE booking_id = :booking_id ORDER BY version DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $latest->execute(['booking_id' => (int) $row['booking_id']]);
            if ((int) $latest->fetchColumn() !== $modificationId) {
                throw new \DomainException('Csak a legutóbbi módosításról küldhető értesítés.');
            }

            $deduplicationKey = 'modification:' . $modificationId;
            $existing = $this->pdo->prepare(
                "SELECT id, booking_id, recipient, payload, status, attempts
                 FROM email_outbox
                 WHERE booking_id = :booking_id AND message_type = 'booking_modified'
                   AND deduplication_key = :deduplication_key
                 LIMIT 1 FOR UPDATE"
            );
            $existing->execute([
                'booking_id' => (int) $row['booking_id'],
                'deduplication_key' => $deduplicationKey,
            ]);
            $outbox = $existing->fetch(PDO::FETCH_ASSOC);

            if ($outbox === false) {
                $payload = $this->newPayload($row, $modificationId);
                $insert = $this->pdo->prepare(
                    "INSERT INTO email_outbox
                        (booking_id, message_type, deduplication_key, recipient, subject, payload, status)
                     VALUES (:booking_id, 'booking_modified', :deduplication_key, :recipient,
                             'Foglalásának adatai módosultak', :payload, 'processing')"
                );
                $insert->execute([
                    'booking_id' => (int) $row['booking_id'],
                    'deduplication_key' => $deduplicationKey,
                    'recipient' => (string) $row['guest_email'],
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $outbox = [
                    'id' => (int) $this->pdo->lastInsertId(),
                    'booking_id' => (int) $row['booking_id'],
                    'recipient' => (string) $row['guest_email'],
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            } else {
                if (!in_array((string) $outbox['status'], ['pending', 'failed'], true)
                    || (int) $outbox['attempts'] >= $this->retryPolicy->maxAttempts()) {
                    $this->pdo->commit();
                    return null;
                }
                $claim = $this->pdo->prepare(
                    "UPDATE email_outbox SET status = 'processing', updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND status = :expected_status AND attempts = :expected_attempts
                       AND attempts < :max_attempts"
                );
                $claim->execute([
                    'id' => (int) $outbox['id'],
                    'expected_status' => (string) $outbox['status'],
                    'expected_attempts' => (int) $outbox['attempts'],
                    'max_attempts' => $this->retryPolicy->maxAttempts(),
                ]);
                if ($claim->rowCount() !== 1) {
                    $this->pdo->commit();
                    return null;
                }
            }

            $item = $this->deliveryItem($outbox);
            $this->pdo->commit();
            return $item;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function markSent(int $outboxId): void
    {
        $statement = $this->pdo->prepare("UPDATE email_outbox SET status='sent', attempts=attempts+1, last_error=NULL, sent_at=CURRENT_TIMESTAMP WHERE id=:id AND status='processing' AND attempts < :max_attempts");
        $statement->execute(['id' => $outboxId, 'max_attempts' => $this->retryPolicy->maxAttempts()]);
    }

    public function markFailed(int $outboxId, string $safeReason): void
    {
        $statement = $this->pdo->prepare("UPDATE email_outbox SET status='failed', attempts=attempts+1, last_error=:reason, sent_at=NULL WHERE id=:id AND status='processing' AND attempts < :max_attempts");
        $statement->execute(['id' => $outboxId, 'reason' => mb_substr($safeReason, 0, 500), 'max_attempts' => $this->retryPolicy->maxAttempts()]);
    }

    public function status(int $modificationId): string
    {
        $statement = $this->pdo->prepare("SELECT status FROM email_outbox WHERE message_type='booking_modified' AND deduplication_key=:deduplication_key LIMIT 1");
        $statement->execute(['deduplication_key' => 'modification:' . $modificationId]);
        $status = $statement->fetchColumn();
        return is_string($status) ? $status : 'not_sent';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function newPayload(array $row, int $modificationId): array
    {
        $after = json_decode((string) $row['after_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($after)) throw new \RuntimeException('A módosítás pillanatképe érvénytelen.');
        foreach (['arrival_date', 'departure_date', 'nights', 'adults', 'accommodation_fee', 'tourism_tax', 'total_amount'] as $field) {
            if (!array_key_exists($field, $after)) throw new \RuntimeException('A módosítás pillanatképe hiányos.');
        }
        return [
            'booking_reference' => (string) $row['reference'],
            'contact_name' => (string) $row['guest_name'],
            'arrival_date' => (string) $after['arrival_date'],
            'departure_date' => (string) $after['departure_date'],
            'nights' => (int) $after['nights'],
            'adults' => (int) $after['adults'],
            'child_ages' => array_map('intval', is_array($after['child_ages'] ?? null) ? $after['child_ages'] : []),
            'accommodation_fee' => (string) $after['accommodation_fee'],
            'tourism_tax' => (string) $after['tourism_tax'],
            'total_amount' => (string) $after['total_amount'],
            'unchanged_deposit_amount' => $row['unchanged_deposit_amount'] !== null ? (string) $row['unchanged_deposit_amount'] : null,
            'currency' => (string) $row['currency'],
            'modification_id' => $modificationId,
        ];
    }

    /** @param array<string,mixed> $row @return array{id:int,booking_id:int,data:BookingModificationMailData} */
    private function deliveryItem(array $row): array
    {
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new \RuntimeException('A módosítási e-mail adatai érvénytelenek.');
        return ['id' => (int) $row['id'], 'booking_id' => (int) $row['booking_id'], 'data' => new BookingModificationMailData(
            (string) $row['recipient'], (string) $payload['contact_name'], (string) $payload['booking_reference'],
            (string) $payload['arrival_date'], (string) $payload['departure_date'], (int) $payload['nights'],
            (int) $payload['adults'], array_map('intval', $payload['child_ages'] ?? []),
            (string) $payload['accommodation_fee'], (string) $payload['tourism_tax'], (string) $payload['total_amount'],
            isset($payload['unchanged_deposit_amount']) ? (string) $payload['unchanged_deposit_amount'] : null,
            (string) $payload['currency'],
        )];
    }
}
