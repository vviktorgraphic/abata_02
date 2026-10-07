<?php
declare(strict_types=1);
namespace App\Application\LegacyImport;

use App\Domain\Booking\BookingStatus;

use App\Application\Booking\LegacyImportProvenanceRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class LegacyImportService
{
    public function __construct(
        private PDO $pdo,
        private LegacyImportProvenanceRepository $provenance,
        private LegacyImportCsvParser $parser,
    ) {}

    public function preview(string $csv): LegacyImportPreview
    {
        return $this->parser->parse($csv);
    }

    public function existingDuplicateCount(LegacyImportPreview $preview, LegacyImportOptions $options): int
    {
        $count = 0;
        foreach ($preview->rows as $row) if ($row->isValid() && $options->accepts($row->sourceStatus, $row->calendarName) && $this->provenance->findBySourceBookingId('wpbs', $row->sourceBookingId) !== null) $count++;
        return $count;
    }

    public function conflictCount(LegacyImportPreview $preview, LegacyImportOptions $options): int
    {
        $count = 0;
        foreach ($preview->rows as $row) if ($row->isValid() && $options->accepts($row->sourceStatus, $row->calendarName) && $row->mappedStatus()?->blocksPublicBooking() === true && $row->arrival !== null && $row->departure !== null && $this->hasConflict($row->arrival->format('Y-m-d'), $row->departure->format('Y-m-d'))) $count++;
        return $count;
    }

    public function import(LegacyImportPreview $preview, LegacyImportOptions $options, ?int $adminId): LegacyImportResult
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $batchId = strtolower(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'));
        $selected = [];
        $duplicates = 0;
        $skipped = 0;
        $invalid = $preview->invalid();
        foreach ($preview->rows as $row) {
            if (!$row->isValid()) continue;
            if (!$options->accepts($row->sourceStatus, $row->calendarName)) { $skipped++; continue; }
            $existing = $this->provenance->findBySourceBookingId('wpbs', $row->sourceBookingId);
            if ($existing !== null) {
                $hash = $this->sourceHash($row);
                if (isset($existing['source_data_hash']) && strtolower((string)$existing['source_data_hash']) !== $hash) {
                    throw new \InvalidArgumentException('A régi foglalás adatai megváltoztak; ellenőrzés szükséges: ' . $row->sourceBookingId);
                }
                $duplicates++; continue;
            }
            $selected[] = $row;
        }
        foreach ($selected as $row) {
            if ($row->arrival === null || $row->departure === null || $row->adults === null || $row->guestName === null || $row->guestEmail === null) {
                throw new \InvalidArgumentException('A kiválasztott sor hiányos adatot tartalmaz.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $batchDbId = $this->provenance->createBatch($batchId, 'wpbs', $adminId, $now);
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
            $imported = 0;
            foreach ($selected as $row) {
                $reference = $this->reference($row->sourceBookingId);
                if ($row->mappedStatus()?->blocksPublicBooking() === true && $this->hasConflict($row->arrival->format('Y-m-d'), $row->departure->format('Y-m-d'))) {
                    throw new \InvalidArgumentException('A kiválasztott blokkoló foglalás ütközik meglévő foglalással vagy zárolt időszakkal.');
                }
                $check = $this->pdo->prepare('SELECT id FROM bookings WHERE reference = :reference');
                $check->execute(['reference' => $reference]);
                if ($check->fetchColumn() !== false) throw new \InvalidArgumentException('A legacy referencia már foglalt: ' . $reference);
                $created = $row->sourceCreatedDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row->sourceCreatedDate)
                    ? $row->sourceCreatedDate . ' 00:00:00' : $now->format('Y-m-d H:i:s');
                $status = $row->mappedStatus()?->value;
                $insert = $this->pdo->prepare(
                    'INSERT INTO bookings (reference,status,arrival_date,departure_date,guest_name,guest_email,guest_phone,adults,children,total_amount,currency,notes,created_at)
                     VALUES (:reference,:status,:arrival,:departure,:name,:email,:phone,:adults,:children,0,\'HUF\',:notes,:created_at)'
                );
                $insert->execute(['reference'=>$reference,'status'=>$status,'arrival'=>$row->arrival->format('Y-m-d'),'departure'=>$row->departure->format('Y-m-d'),'name'=>$row->guestName,'email'=>$row->guestEmail,'phone'=>$row->guestPhone,'adults'=>$row->adults,'children'=>$row->children ?? 0,'notes'=>$row->notes,'created_at'=>$created]);
                $bookingId = (int)$this->pdo->lastInsertId();
                foreach ($row->childAges as $position => $age) {
                    $ageInsert = $this->pdo->prepare('INSERT INTO booking_child_ages (booking_id,position,age) VALUES (:booking_id,:position,:age)');
                    $ageInsert->execute(['booking_id'=>$bookingId,'position'=>$position+1,'age'=>$age]);
                }
                $history = $this->pdo->prepare('INSERT INTO booking_status_history (booking_id,old_status,new_status,changed_by_admin_id,note) VALUES (:booking_id,NULL,:status,:admin_id,:note)');
                $history->execute(['booking_id'=>$bookingId,'status'=>$status,'admin_id'=>$adminId,'note'=>'Imported from WP Booking System; source booking ID ' . $row->sourceBookingId . '; source status ' . $row->sourceStatus . '.']);
                $this->provenance->record([
                    'source_system'=>'wpbs','source_booking_id'=>$row->sourceBookingId,'booking_id'=>$bookingId,'source_status'=>$row->sourceStatus,'mapped_status'=>$status,
                    'source_calendar_id'=>$row->calendarId,'source_calendar_name'=>$row->calendarName,'source_created_date'=>$row->sourceCreatedDate,'source_data_hash'=>$this->sourceHash($row),
                    'source_privacy_evidence_present'=>$row->privacyAccepted,'source_booking_policy_evidence_present'=>$row->bookingPolicyAccepted,'source_house_rules_evidence_present'=>$row->houseRulesAccepted,'pricing_unavailable'=>true,'imported_by_admin_id'=>$adminId,'imported_at'=>$now->format('Y-m-d H:i:s'),
                ], $batchDbId);
                $imported++;
            }
            $this->provenance->completeBatch($batchDbId, 'committed', ['total_rows'=>$preview->total(),'imported_rows'=>$imported,'duplicate_rows'=>$duplicates,'skipped_rows'=>$skipped,'invalid_rows'=>$invalid], $now);
            $this->pdo->commit();
            return new LegacyImportResult($batchId,$imported,$duplicates,$skipped,$invalid);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function reference(string $sourceId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9-]/', '-', trim($sourceId)) ?: 'UNKNOWN';
        return 'WPBS-' . substr($safe, 0, 18) . '-' . substr(hash('sha256', $sourceId), 0, 8);
    }

    private function sourceHash(LegacyImportRow $row): string
    {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function hasConflict(string $arrival, string $departure): bool
    {
        $statusPlaceholders = implode(', ', array_fill(0, count(BookingStatus::BLOCKING_VALUES), '?'));
        $query = $this->pdo->prepare("SELECT 1 FROM bookings WHERE status IN ({$statusPlaceholders}) AND arrival_date < ? AND departure_date > ? LIMIT 1");
        $query->execute([...BookingStatus::BLOCKING_VALUES, $departure, $arrival]);
        if ($query->fetchColumn() !== false) return true;
        $query = $this->pdo->prepare('SELECT 1 FROM blocked_periods WHERE is_active = TRUE AND start_date < :departure AND end_date > :arrival LIMIT 1');
        $query->execute(['arrival'=>$arrival,'departure'=>$departure]);
        return $query->fetchColumn() !== false;
    }
}
