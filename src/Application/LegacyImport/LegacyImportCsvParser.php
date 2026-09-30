<?php
declare(strict_types=1);
namespace App\Application\LegacyImport;

use DateTimeImmutable;
use DateTimeZone;

final class LegacyImportCsvParser
{
    private const HEADERS = [
        'Booking ID','Booking Status','Calendar ID','Calendar Name','Start Date','End Date',
        'Stay Length - Days','Stay Length - Nights','Date Created','Név (ID:1)','Email (ID:2)',
        'Telefonszám (ID:3)','Vendégek száma (ID:7)','Ebből 18 év alatti (ID:8)',
        'Ebből 18 év alatti (ID:14)','Ebből 18 év alatti (ID:15)','18 év alatti kora 1 (ID:11)',
        '18 év alatti kora 2 (ID:12)','18 év alatti kora 3 (ID:13)','Megjegyzés (ID:6)',
        'Elolvastam és elfogadom az Adatvédelmi irányelveket. (ID:5)',
        'Elolvastam és elfogadom a Foglalási szabályzatot. (ID:16)',
        'Elolvastam és elfogadom a Házirendet. (ID:17)',
    ];

    public function parse(string $csv): LegacyImportPreview
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv); rewind($stream);
        $header = fgetcsv($stream);
        if ($header === false) throw new \InvalidArgumentException('CSV is empty.');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $indexes = [];
        foreach ($header as $index => $name) $indexes[trim((string) $name)] = $index;
        foreach (self::HEADERS as $required) if (!array_key_exists($required, $indexes)) throw new \InvalidArgumentException('Required CSV column is missing.');
        $rows = []; $statuses = []; $calendars = []; $seen = [];
        while (($values = fgetcsv($stream)) !== false) {
            if ($values === [null] || count(array_filter($values, static fn ($v): bool => trim((string) $v) !== '')) === 0) continue;
            $get = static function(string $key) use ($values, $indexes): string { return trim((string) ($values[$indexes[$key]] ?? '')); };
            $id = $get('Booking ID'); $status = strtolower($get('Booking Status')); $calendar = $get('Calendar Name');
            $errors = []; $warnings = [];
            if ($id === '') $errors[] = 'missing_source_id';
            if (isset($seen[$id])) $errors[] = 'duplicate_source_id'; else $seen[$id] = true;
            if (!in_array($status, ['accepted','pending','trash'], true)) $errors[] = 'unknown_source_status';
            $arrival = $this->date($get('Start Date'), $errors, 'invalid_arrival_date');
            $departure = $this->date($get('End Date'), $errors, 'invalid_departure_date');
            if ($arrival === null && $get('Start Date') === '') $errors[] = 'missing_arrival_date';
            if ($departure === null && $get('End Date') === '') $errors[] = 'missing_departure_date';
            $nights = $this->integer($get('Stay Length - Nights'), $errors, 'invalid_nights');
            $days = $this->integer($get('Stay Length - Days'), $errors, 'invalid_days');
            if ($arrival && $departure) {
                $calculated = (int) $arrival->diff($departure)->format('%r%a');
                if ($calculated <= 0) $errors[] = 'departure_not_after_arrival';
                if ($nights !== null && $calculated !== $nights) $errors[] = 'nights_mismatch';
                if ($days !== null && $days !== $calculated + 1) $errors[] = 'days_mismatch';
            }
            $total = $this->integer($get('Vendégek száma (ID:7)'), $errors, 'invalid_total_guests', allowEmpty: true);
            $childValues = [];
            foreach (['Ebből 18 év alatti (ID:8)','Ebből 18 év alatti (ID:14)','Ebből 18 év alatti (ID:15)'] as $key) {
                $raw = $get($key); if ($raw === '' || $raw === '-') continue;
                $childValues[] = $this->integer($raw, $errors, 'invalid_child_count');
            }
            $childValues = array_values(array_filter($childValues, static fn ($v): bool => $v !== null));
            if (count(array_unique($childValues)) > 1) $errors[] = 'conflicting_child_counts';
            $children = $childValues[0] ?? ($total === null ? null : 0);
            $adults = null;
            if ($total === null && $status !== 'trash') $errors[] = 'missing_total_guests';
            if ($total !== null) {
                if ($total <= 0) $errors[] = 'non_positive_total_guests';
                if (($children ?? 0) < 0 || ($children ?? 0) > $total) $errors[] = 'invalid_children_total';
                $adults = $total - ($children ?? 0); if ($adults < 1) $errors[] = 'missing_adult_guest';
            }
            $ages = [];
            foreach (['18 év alatti kora 1 (ID:11)','18 év alatti kora 2 (ID:12)','18 év alatti kora 3 (ID:13)'] as $key) {
                $raw = $get($key); if ($raw === '' || $raw === '-') continue;
                if (!preg_match('/^(\d{1,2})\s*év$/u', $raw, $m)) { $errors[] = 'invalid_child_age'; continue; }
                $age = (int) $m[1]; if ($age < 0 || $age > 17) $errors[] = 'invalid_child_age'; else $ages[] = $age;
            }
            if ($children !== null && count($ages) !== $children) $errors[] = 'child_age_count_mismatch';
            $email = $get('Email (ID:2)');
            if ($email === '' && $status !== 'trash') $errors[] = 'missing_email';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'invalid_email';
            $name = $this->nullable($get('Név (ID:1)'));
            if ($name === null && $status !== 'trash') $errors[] = 'missing_guest_name';
            $note = $get('Megjegyzés (ID:6)'); $note = $note === '-' || $note === '' ? null : $note;
            $sourceDate = $get('Date Created'); if ($sourceDate !== '') { $createdErrors = []; $this->date($sourceDate, $createdErrors, 'ignored'); if ($createdErrors !== []) { $warnings[] = 'invalid_created_date'; $sourceDate = null; } }
            $row = new LegacyImportRow($id,$status,$get('Calendar ID'),$calendar,$arrival,$departure,$nights,$days,$sourceDate,
                $name,$this->nullable($email),$this->nullable($get('Telefonszám (ID:3)')),$total,$children,$adults,$ages,$note,
                $this->truthy($get('Elolvastam és elfogadom az Adatvédelmi irányelveket. (ID:5)')),
                $this->truthy($get('Elolvastam és elfogadom a Foglalási szabályzatot. (ID:16)')),
                $this->truthy($get('Elolvastam és elfogadom a Házirendet. (ID:17)')),$errors,$warnings);
            $rows[] = $row; $statuses[$status] = ($statuses[$status] ?? 0) + 1; $calendars[$calendar] = ($calendars[$calendar] ?? 0) + 1;
        }
        fclose($stream); return new LegacyImportPreview($rows,$statuses,$calendars);
    }

    private function date(string $raw, array &$errors, string $error): ?DateTimeImmutable
    { if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) { if ($raw !== '') $errors[] = $error; return null; } $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Europe/Budapest')); if (!$date || $date->format('Y-m-d') !== $raw) $errors[] = $error; return $date ?: null; }
    private function integer(string $raw, array &$errors, string $error, bool $allowEmpty = false): ?int
    { if ($raw === '' || $raw === '-') { if (!$allowEmpty) return null; return null; } if (!preg_match('/^\d+$/', $raw)) { $errors[] = $error; return null; } return (int) $raw; }
    private function nullable(string $v): ?string { return $v === '' || $v === '-' ? null : $v; }
    private function truthy(string $v): bool { return in_array(mb_strtolower($v), ['1','yes','true','igen','on','accepted','x'], true); }
}
