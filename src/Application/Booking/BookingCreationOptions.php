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
        public ?string $expectedCalculatedTotal,
        public ?string $expectedPricingHash,
        public ?string $customTotal,
        public ?string $expectedEffectiveTotal,
        public ?string $expectedCurrency,
    ) {
    }

    public static function publicRequest(): self
    {
        return new self(true, true, null, 'Public booking request created', null, null, null, null, null);
    }

    public static function adminManual(
        int $adminId,
        string $expectedCalculatedTotal,
        string $expectedPricingHash,
        ?string $customTotal,
        string $expectedEffectiveTotal,
        string $expectedCurrency,
    ): self {
        if ($adminId < 1
            || preg_match('/^\d+\.\d{2}$/D', $expectedCalculatedTotal) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $expectedPricingHash) !== 1
            || ($customTotal !== null && preg_match('/^\d+\.00$/D', $customTotal) !== 1)
            || preg_match('/^\d+\.\d{2}$/D', $expectedEffectiveTotal) !== 1
            || $expectedCurrency !== 'HUF') {
            throw new \InvalidArgumentException('Invalid admin booking creation options.');
        }

        return new self(
            false,
            false,
            $adminId,
            'Admin által rögzített foglalás',
            $expectedCalculatedTotal,
            $expectedPricingHash,
            $customTotal,
            $expectedEffectiveTotal,
            $expectedCurrency,
        );
    }
}
