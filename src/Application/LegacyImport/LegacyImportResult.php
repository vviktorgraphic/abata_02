<?php
declare(strict_types=1);
namespace App\Application\LegacyImport;

final readonly class LegacyImportResult
{
    public function __construct(
        public string $batchId,
        public int $imported,
        public int $duplicates,
        public int $skipped,
        public int $invalid,
    ) {}
}
