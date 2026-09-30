<?php
declare(strict_types=1);
namespace App\Application\LegacyImport;

final readonly class LegacyImportPreview
{
    /** @param list<LegacyImportRow> $rows @param array<string,int> $sourceStatuses @param array<string,int> $calendars */
    public function __construct(public array $rows, public array $sourceStatuses, public array $calendars) {}

    public function total(): int { return count($this->rows); }
    public function valid(): int { return count(array_filter($this->rows, static fn (LegacyImportRow $r): bool => $r->isValid())); }
    public function invalid(): int { return $this->total() - $this->valid(); }
    public function selected(LegacyImportOptions $options): int
    {
        return count(array_filter($this->rows, static fn (LegacyImportRow $r): bool => $r->isValid() && $options->accepts($r->sourceStatus, $r->calendarName)));
    }

    /** @return array<string,int> */
    public function mappedStatuses(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $status = $row->mappedStatus()?->value;
            if ($status !== null) $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }

    public function warningCount(): int
    {
        return array_sum(array_map(static fn (LegacyImportRow $row): int => count($row->warnings), $this->rows));
    }

    public function errorCount(): int
    {
        return array_sum(array_map(static fn (LegacyImportRow $row): int => count($row->errors), $this->rows));
    }
}
