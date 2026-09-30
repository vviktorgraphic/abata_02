<?php
declare(strict_types=1);
namespace App\Application\LegacyImport;

use App\Domain\Booking\BookingStatus;
use DateTimeImmutable;

final readonly class LegacyImportRow
{
    /** @param list<int> $childAges @param list<string> $errors @param list<string> $warnings */
    public function __construct(
        public string $sourceBookingId,
        public string $sourceStatus,
        public string $calendarId,
        public string $calendarName,
        public ?DateTimeImmutable $arrival,
        public ?DateTimeImmutable $departure,
        public ?int $nights,
        public ?int $days,
        public ?string $sourceCreatedDate,
        public ?string $guestName,
        public ?string $guestEmail,
        public ?string $guestPhone,
        public ?int $totalGuests,
        public ?int $children,
        public ?int $adults,
        public array $childAges,
        public ?string $notes,
        public bool $privacyAccepted,
        public bool $bookingPolicyAccepted,
        public bool $houseRulesAccepted,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function mappedStatus(): ?BookingStatus
    {
        return match ($this->sourceStatus) {
            'accepted' => BookingStatus::Confirmed,
            'pending' => BookingStatus::Pending,
            'trash' => BookingStatus::Invalidated,
            default => null,
        };
    }

    public function isValid(): bool { return $this->errors === []; }
}
