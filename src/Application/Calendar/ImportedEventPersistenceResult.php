<?php

declare(strict_types=1);

namespace App\Application\Calendar;

final readonly class ImportedEventPersistenceResult
{
    public const BLOCKED = 'blocked';
    public const DUPLICATE = 'duplicate';
    public const CONFLICT = 'conflict';
    public const REMOVED = 'removed';

    public function __construct(
        public string $outcome,
        public int $eventId,
        public ?int $blockedPeriodId,
    ) {
        if (!in_array($outcome, [self::BLOCKED, self::DUPLICATE, self::CONFLICT, self::REMOVED], true)) {
            throw new \InvalidArgumentException('Invalid imported event persistence outcome.');
        }
    }
}
