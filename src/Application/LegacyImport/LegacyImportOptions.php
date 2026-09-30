<?php
declare(strict_types=1);
namespace App\Application\LegacyImport;

final readonly class LegacyImportOptions
{
    /** @param list<string> $statuses @param list<string> $calendarNames */
    public function __construct(
        public array $statuses = ['accepted', 'pending'],
        public array $calendarNames = ['A Bata - naptár'],
    ) {}

    public function accepts(string $status, string $calendar): bool
    {
        return in_array($status, $this->statuses, true) && in_array($calendar, $this->calendarNames, true);
    }
}
