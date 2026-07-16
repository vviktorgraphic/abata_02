<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPersistenceResult;
use App\Application\Booking\BookingPricingProvider;
use App\Application\Booking\IdempotencyConflict;
use PDO;
use Throwable;

final class TransactionalBookingRepository
{
    /** @param null|\Closure(string): void $transactionProbe Test-only failure probe. */
    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $transactionProbe = null)
    {
    }

    public function create(
        BookingPersistenceCommand $command,
        BookingPricingProvider $pricingProvider,
    ): BookingPersistenceResult {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Booking persistence owns its transaction boundary.');
        }

        $this->pdo->beginTransaction();

        try {
            // One accommodation means one inventory row is sufficient. This serializes
            // availability rechecks with inserts without relying on process-local locks.
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();

            $keyHash = hash('sha256', $command->idempotencyKey);
            $existing = $this->findIdempotentResult($keyHash, $command->requestHash);
            if ($existing !== null) {
                $this->pdo->commit();

                return $existing;
            }

            $this->assertAvailable($command->arrivalDate, $command->departureDate);

            $claim = $this->pdo->prepare(
                'INSERT INTO booking_idempotency (key_hash, request_hash, booking_id)
                 VALUES (UNHEX(:key_hash), UNHEX(:request_hash), NULL)'
            );
            $claim->execute(['key_hash' => $keyHash, 'request_hash' => $command->requestHash]);
            $claimId = (int) $this->pdo->lastInsertId();

            // Pricing deliberately runs inside this transaction, after the final
            // availability check, so the persisted amount and snapshot are one unit.
            $pricing = $pricingProvider->calculate($this->pdo, $command);

            $booking = $this->pdo->prepare(
                'INSERT INTO bookings
                    (reference, status, arrival_date, departure_date, guest_name, guest_email,
                     guest_phone, adults, children, total_amount, currency, notes)
                 VALUES
                    (:reference, \'pending\', :arrival, :departure, :name, :email,
                     :phone, :adults, :children, :total, :currency, :notes)'
            );
            $booking->execute([
                'reference' => $command->reference,
                'arrival' => $command->arrivalDate,
                'departure' => $command->departureDate,
                'name' => $command->contactName,
                'email' => $command->email,
                'phone' => $command->phone,
                'adults' => $command->adults,
                'children' => count($command->childAges),
                'total' => $pricing->totalAmount,
                'currency' => $pricing->currency,
                'notes' => $command->notes,
            ]);
            $bookingId = (int) $this->pdo->lastInsertId();
            ($this->transactionProbe ?? static fn (string $stage): null => null)('booking_inserted');

            $child = $this->pdo->prepare(
                'INSERT INTO booking_child_ages (booking_id, position, age)
                 VALUES (:booking_id, :position, :age)'
            );
            foreach ($command->childAges as $position => $age) {
                $child->execute([
                    'booking_id' => $bookingId,
                    'position' => $position,
                    'age' => $age,
                ]);
            }

            $history = $this->pdo->prepare(
                'INSERT INTO booking_status_history
                    (booking_id, old_status, new_status, changed_by_admin_id, note)
                 VALUES (:booking_id, NULL, \'pending\', NULL, :note)'
            );
            $history->execute(['booking_id' => $bookingId, 'note' => 'Public booking request created']);
            ($this->transactionProbe ?? static fn (string $stage): null => null)('history_inserted');

            $snapshotJson = json_encode($pricing->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $snapshot = $this->pdo->prepare(
                'INSERT INTO booking_pricing_snapshots (booking_id, snapshot)
                 VALUES (:booking_id, :snapshot)'
            );
            $snapshot->execute(['booking_id' => $bookingId, 'snapshot' => $snapshotJson]);

            $outboxPayload = json_encode([
                'booking_reference' => $command->reference,
                'arrival_date' => $command->arrivalDate,
                'departure_date' => $command->departureDate,
                'adults' => $command->adults,
                'child_ages' => $command->childAges,
                'total' => $pricing->totalAmount,
                'currency' => $pricing->currency,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $outbox = $this->pdo->prepare(
                'INSERT INTO email_outbox
                    (booking_id, message_type, recipient, subject, payload, status)
                 VALUES
                    (:booking_id, \'booking_request_received\', :recipient, :subject, :payload, \'pending\')'
            );
            $outbox->execute([
                'booking_id' => $bookingId,
                'recipient' => $command->email,
                'subject' => 'A Bata foglalási igény',
                'payload' => $outboxPayload,
            ]);

            $bindClaim = $this->pdo->prepare(
                'UPDATE booking_idempotency SET booking_id = :booking_id WHERE id = :id'
            );
            $bindClaim->execute(['booking_id' => $bookingId, 'id' => $claimId]);

            $this->pdo->commit();

            return new BookingPersistenceResult(
                $bookingId,
                $command->reference,
                'pending',
                $pricing->totalAmount,
                $pricing->currency,
                false,
            );
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    private function findIdempotentResult(string $keyHash, string $requestHash): ?BookingPersistenceResult
    {
        $statement = $this->pdo->prepare(
            'SELECT HEX(i.request_hash) AS request_hash, b.id, b.reference, b.status,
                    b.total_amount, b.currency
             FROM booking_idempotency i
             LEFT JOIN bookings b ON b.id = i.booking_id
             WHERE i.key_hash = UNHEX(:key_hash)
             FOR UPDATE'
        );
        $statement->execute(['key_hash' => $keyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!hash_equals(strtolower((string) $row['request_hash']), $requestHash)) {
            throw new IdempotencyConflict('The idempotency key was already used with a different request.');
        }
        if ($row['id'] === null) {
            throw new \RuntimeException('An incomplete idempotency claim was found.');
        }

        return new BookingPersistenceResult(
            (int) $row['id'],
            (string) $row['reference'],
            (string) $row['status'],
            number_format((float) $row['total_amount'], 2, '.', ''),
            (string) $row['currency'],
            true,
        );
    }

    private function assertAvailable(string $arrival, string $departure): void
    {
        $confirmed = $this->pdo->prepare(
            'SELECT id FROM bookings
             WHERE status = \'confirmed\'
               AND arrival_date < :departure
               AND departure_date > :arrival
             LIMIT 1'
        );
        $confirmed->execute(['arrival' => $arrival, 'departure' => $departure]);
        if ($confirmed->fetchColumn() !== false) {
            throw new BookingConflict('The requested period overlaps a confirmed booking.');
        }

        $blocked = $this->pdo->prepare(
            'SELECT id FROM blocked_periods
             WHERE start_date < :departure
               AND end_date > :arrival
             LIMIT 1'
        );
        $blocked->execute(['arrival' => $arrival, 'departure' => $departure]);
        if ($blocked->fetchColumn() !== false) {
            throw new BookingConflict('The requested period overlaps a blocked period.');
        }
    }
}
