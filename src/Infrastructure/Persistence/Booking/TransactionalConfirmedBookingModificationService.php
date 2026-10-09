<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingModificationNotAllowed;
use App\Application\Booking\BookingModificationPreview;
use App\Application\Booking\BookingModificationResult;
use App\Application\Booking\BookingModificationStale;
use App\Application\Booking\BookingNotFound;
use App\Application\Booking\ConfirmedBookingModification;
use App\Application\Booking\IdempotencyConflict;
use App\Domain\Booking\BookingStatus;
use App\Domain\Pricing\PricingInput;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use PDO;
use Throwable;

final readonly class TransactionalConfirmedBookingModificationService
{
    /** @param null|\Closure(string):void $transactionProbe Test-only failure probe. */
    public function __construct(
        private PDO $pdo,
        private PdoPricingEngineAdapter $pricing,
        private ?\Closure $transactionProbe = null,
    )
    {
    }

    public function preview(string $reference, ConfirmedBookingModification $request): BookingModificationPreview
    {
        $booking = $this->booking($reference, false);
        $this->assertConfirmed($booking);
        $this->assertAvailable((int) $booking['id'], $request->arrivalDate, $request->departureDate);
        $pricing = $this->pricing->preview(new PricingInput(
            $request->arrivalDate, $request->departureDate, $request->adults, $request->childAges
        ));

        return new BookingModificationPreview(
            (int) $booking['id'], (int) $booking['modification_version'], $request, $pricing,
            $this->depositAmount((int) $booking['id']), $this->pricingHash($pricing->snapshot)
        );
    }

    public function modify(
        string $reference,
        ConfirmedBookingModification $request,
        int $expectedVersion,
        string $expectedPricingHash,
        string $idempotencyKey,
        int $adminId,
    ): BookingModificationResult {
        if ($this->pdo->inTransaction()) throw new \LogicException('Booking modification owns its transaction boundary.');
        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Érvényes idempotenciakulcs szükséges.');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', json_encode([
            'reference' => $reference, 'expected_version' => $expectedVersion,
            'expected_pricing_hash' => $expectedPricingHash, ...$request->canonicalPayload(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
            $booking = $this->booking($reference, true);
            $existing = $this->idempotentResult((int) $booking['id'], $keyHash, $requestHash);
            if ($existing !== null) {
                $this->pdo->commit();
                return $existing;
            }
            $this->assertConfirmed($booking);
            if ((int) $booking['modification_version'] !== $expectedVersion) {
                throw new BookingModificationStale('A foglalás az előnézet óta megváltozott. Készítsen új előnézetet.');
            }
            $bookingId = (int) $booking['id'];
            $this->assertAvailable($bookingId, $request->arrivalDate, $request->departureDate);
            $pricing = $this->pricing->calculateFor($this->pdo, new PricingInput(
                $request->arrivalDate, $request->departureDate, $request->adults, $request->childAges
            ));
            if (!hash_equals($expectedPricingHash, $this->pricingHash($pricing->snapshot))) {
                throw new BookingModificationStale('Az árbeállítás az előnézet óta megváltozott. Készítsen új előnézetet.');
            }
            $oldPricing = $this->pricingSnapshot($bookingId, true);
            $oldAges = $this->childAges($bookingId, true);
            $deposit = $this->depositAmount($bookingId, true);
            $newVersion = $expectedVersion + 1;

            $update = $this->pdo->prepare(
                'UPDATE bookings SET arrival_date = :arrival, departure_date = :departure,
                    adults = :adults, children = :children, total_amount = :total, currency = :currency,
                    modification_version = :new_version
                 WHERE id = :id AND status = \'confirmed\' AND modification_version = :expected_version'
            );
            $update->execute([
                'arrival' => $request->arrivalDate, 'departure' => $request->departureDate,
                'adults' => $request->adults, 'children' => count($request->childAges),
                'total' => $pricing->totalAmount, 'currency' => $pricing->currency,
                'new_version' => $newVersion, 'id' => $bookingId, 'expected_version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new BookingModificationStale('A foglalás az előnézet óta megváltozott. Készítsen új előnézetet.');
            }
            ($this->transactionProbe ?? static fn (string $stage): null => null)('booking_updated');

            $deleteAges = $this->pdo->prepare('DELETE FROM booking_child_ages WHERE booking_id = :booking_id');
            $deleteAges->execute(['booking_id' => $bookingId]);
            $insertAge = $this->pdo->prepare(
                'INSERT INTO booking_child_ages (booking_id, position, age) VALUES (:booking_id, :position, :age)'
            );
            foreach ($request->childAges as $position => $age) {
                $insertAge->execute(['booking_id' => $bookingId, 'position' => $position, 'age' => $age]);
            }

            $snapshotUpdate = $this->pdo->prepare(
                'UPDATE booking_pricing_snapshots SET snapshot = :snapshot WHERE booking_id = :booking_id'
            );
            $snapshotUpdate->execute([
                'snapshot' => json_encode($pricing->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'booking_id' => $bookingId,
            ]);
            if ($snapshotUpdate->rowCount() !== 1) throw new \RuntimeException('A foglalás ár-pillanatképe hiányzik.');

            $before = $this->stateSnapshot($booking, $oldAges, $oldPricing);
            $after = [
                'arrival_date' => $request->arrivalDate, 'departure_date' => $request->departureDate,
                'nights' => $request->nights(), 'adults' => $request->adults,
                'children' => count($request->childAges), 'child_ages' => $request->childAges,
                'accommodation_fee' => $pricing->accommodationFee, 'tourism_tax' => $pricing->tourismTax,
                'total_amount' => $pricing->totalAmount, 'currency' => $pricing->currency,
                'pricing_snapshot' => $pricing->snapshot,
            ];
            $modification = $this->pdo->prepare(
                'INSERT INTO booking_modifications
                    (booking_id, version, changed_by_admin_id, idempotency_key_hash, request_hash,
                     before_snapshot, after_snapshot, unchanged_deposit_amount, currency)
                 VALUES (:booking_id, :version, :admin_id, UNHEX(:key_hash), UNHEX(:request_hash),
                         :before_snapshot, :after_snapshot, :deposit, :currency)'
            );
            $modification->execute([
                'booking_id' => $bookingId, 'version' => $newVersion, 'admin_id' => $adminId,
                'key_hash' => $keyHash, 'request_hash' => $requestHash,
                'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'deposit' => $deposit, 'currency' => $pricing->currency,
            ]);
            $modificationId = (int) $this->pdo->lastInsertId();

            $audit = $this->pdo->prepare(
                'INSERT INTO audit_logs (event_type, admin_id, target_type, target_id, outcome, metadata_json)
                 VALUES (\'booking_modified\', :admin_id, \'booking\', :target_id, \'success\', :metadata)'
            );
            $audit->execute([
                'admin_id' => $adminId, 'target_id' => (string) $bookingId,
                'metadata' => json_encode([
                    'booking_reference' => $reference, 'modification_id' => $modificationId,
                    'version' => $newVersion, 'before' => $this->auditState($before),
                    'after' => $this->auditState($after), 'unchanged_deposit_amount' => $deposit,
                    'currency' => $pricing->currency,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
            ($this->transactionProbe ?? static fn (string $stage): null => null)('audit_inserted');

            $payload = [
                'booking_reference' => $reference, 'contact_name' => (string) $booking['guest_name'],
                ...$this->auditState($after), 'unchanged_deposit_amount' => $deposit,
                'currency' => $pricing->currency, 'modification_id' => $modificationId,
            ];
            $outbox = $this->pdo->prepare(
                'INSERT INTO email_outbox
                    (booking_id, message_type, deduplication_key, recipient, subject, payload, status)
                 VALUES (:booking_id, \'booking_modified\', :deduplication_key, :recipient,
                         \'Foglalásának adatai módosultak\', :payload, \'pending\')'
            );
            $outbox->execute([
                'booking_id' => $bookingId, 'deduplication_key' => 'modification:' . $modificationId,
                'recipient' => (string) $booking['guest_email'],
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            ($this->transactionProbe ?? static fn (string $stage): null => null)('outbox_inserted');

            $this->pdo->commit();
            return new BookingModificationResult($bookingId, $modificationId, $newVersion);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function booking(string $reference, bool $lock): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, reference, status, arrival_date, departure_date, adults, children,
                    total_amount, currency, guest_name, guest_email, modification_version
             FROM bookings WHERE reference = :reference' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['reference' => $reference]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if ($booking === false) throw new BookingNotFound('A foglalás nem található.');
        return $booking;
    }

    /** @param array<string,mixed> $booking */
    private function assertConfirmed(array $booking): void
    {
        if ($booking['status'] !== 'confirmed') {
            throw new BookingModificationNotAllowed('Csak megerősített foglalás módosítható.');
        }
    }

    private function assertAvailable(int $bookingId, string $arrival, string $departure): void
    {
        $placeholders = implode(', ', array_fill(0, count(BookingStatus::BLOCKING_VALUES), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM bookings WHERE id <> ? AND status IN ({$placeholders})
             AND arrival_date < ? AND departure_date > ? LIMIT 1"
        );
        $statement->execute([$bookingId, ...BookingStatus::BLOCKING_VALUES, $departure, $arrival]);
        if ($statement->fetchColumn() !== false) throw new BookingConflict('A megadott időszak másik foglalással ütközik.');
        $statement = $this->pdo->prepare(
            'SELECT id FROM blocked_periods WHERE is_active = TRUE
             AND start_date < :departure AND end_date > :arrival LIMIT 1'
        );
        $statement->execute(['departure' => $departure, 'arrival' => $arrival]);
        if ($statement->fetchColumn() !== false) throw new BookingConflict('A megadott időszak aktív blokkolással ütközik.');
    }

    /** @return list<int> */
    private function childAges(int $bookingId, bool $lock): array
    {
        $statement = $this->pdo->prepare(
            'SELECT age FROM booking_child_ages WHERE booking_id = :booking_id ORDER BY position' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['booking_id' => $bookingId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function pricingSnapshot(int $bookingId, bool $lock): array
    {
        $statement = $this->pdo->prepare(
            'SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id = :booking_id' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['booking_id' => $bookingId]);
        $json = $statement->fetchColumn();
        if (!is_string($json)) throw new \RuntimeException('A foglalás ár-pillanatképe hiányzik.');
        $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new \RuntimeException('A foglalás ár-pillanatképe érvénytelen.');
        return $snapshot;
    }

    private function depositAmount(int $bookingId, bool $lock = false): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT payload FROM email_outbox WHERE booking_id = :booking_id
             AND message_type = 'booking_payment_request' LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['booking_id' => $bookingId]);
        $json = $statement->fetchColumn();
        if (!is_string($json)) return null;
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return isset($payload['advance_amount']) ? (string) $payload['advance_amount'] : null;
    }

    /** @param array<string,mixed> $booking @param list<int> $ages @param array<string,mixed> $pricing */
    private function stateSnapshot(array $booking, array $ages, array $pricing): array
    {
        return [
            'arrival_date' => (string) $booking['arrival_date'], 'departure_date' => (string) $booking['departure_date'],
            'nights' => (int) (new \DateTimeImmutable((string) $booking['arrival_date']))->diff(new \DateTimeImmutable((string) $booking['departure_date']))->days,
            'adults' => (int) $booking['adults'], 'children' => (int) $booking['children'], 'child_ages' => $ages,
            'accommodation_fee' => $pricing['accommodation_fee'] ?? null,
            'tourism_tax' => $pricing['taxes'] ?? null, 'total_amount' => (string) $booking['total_amount'],
            'currency' => (string) $booking['currency'], 'pricing_snapshot' => $pricing,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function auditState(array $state): array
    {
        return array_intersect_key($state, array_flip([
            'arrival_date', 'departure_date', 'nights', 'adults', 'children', 'child_ages',
            'accommodation_fee', 'tourism_tax', 'total_amount', 'currency',
        ]));
    }

    private function idempotentResult(int $bookingId, string $keyHash, string $requestHash): ?BookingModificationResult
    {
        $statement = $this->pdo->prepare(
            'SELECT id, version, HEX(request_hash) AS request_hash FROM booking_modifications
             WHERE booking_id = :booking_id AND idempotency_key_hash = UNHEX(:key_hash) FOR UPDATE'
        );
        $statement->execute(['booking_id' => $bookingId, 'key_hash' => $keyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (!hash_equals(strtolower((string) $row['request_hash']), $requestHash)) {
            throw new IdempotencyConflict('Az idempotenciakulcsot már más módosításhoz használták.');
        }
        return new BookingModificationResult($bookingId, (int) $row['id'], (int) $row['version'], true);
    }

    /** @param array<string,mixed> $snapshot */
    private function pricingHash(array $snapshot): string
    {
        unset($snapshot['calculated_at']);
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
