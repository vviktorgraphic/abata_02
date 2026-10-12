<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class BookingCreationOptions
{
    private function __construct(
        public bool $recordLegalAcceptance,
        public bool $queueCreationEmails,
        public ?int $adminId,
        public string $historyNote,
        public ?string $expectedTotal,
        public ?string $expectedCurrency,
    ) {
    }

    public static function publicRequest(): self
    {
        return new self(true, true, null, 'Public booking request created', null, null);
    }

    public static function adminManual(int $adminId, string $expectedTotal, string $expectedCurrency): self
    {
        if ($adminId < 1 || preg_match('/^\d+\.\d{2}$/D', $expectedTotal) !== 1 || $expectedCurrency !== 'HUF') {
            throw new \InvalidArgumentException('Invalid admin booking creation options.');
        }

        return new self(false, false, $adminId, 'Admin által rögzített foglalás', $expectedTotal, $expectedCurrency);
    }
}
