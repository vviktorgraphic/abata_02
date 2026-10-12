<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

final readonly class MigrationStatusResult
{
    /**
     * @param list<string> $pending
     * @param list<string> $unknownApplied
     */
    public function __construct(
        public string $status,
        public int $expectedCount,
        public int $appliedCount,
        public array $pending,
        public array $unknownApplied,
    ) {
    }

    public function exitCode(): int
    {
        return $this->status === 'ok' ? 0 : 1;
    }

    /** @return array{status: string, expected_count: int, applied_count: int, pending: list<string>, unknown_applied: list<string>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'expected_count' => $this->expectedCount,
            'applied_count' => $this->appliedCount,
            'pending' => $this->pending,
            'unknown_applied' => $this->unknownApplied,
        ];
    }
}
