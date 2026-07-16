<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;

final readonly class BookingCreateRequest
{
    /** @param list<int> $childAges */
    public function __construct(
        public BookingPeriod $period,
        public string $contactName,
        public string $email,
        public string $phone,
        public int $adults,
        public int $children,
        public array $childAges,
        public string $notes,
        public bool $privacyAccepted,
        public string $idempotencyKey,
    ) {
    }

    public function nights(): int
    {
        return (int) $this->period->arrival->diff($this->period->departure)->days;
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'adults' => $this->adults,
            'arrival_date' => $this->period->arrival->format('Y-m-d'),
            'child_ages' => $this->childAges,
            'children' => $this->children,
            'contact_name' => $this->contactName,
            'departure_date' => $this->period->departure->format('Y-m-d'),
            'email' => $this->email,
            'notes' => $this->notes,
            'phone' => $this->phone,
            'privacy_accepted' => $this->privacyAccepted,
        ];
    }

    public function canonicalHash(): string
    {
        $json = json_encode($this->canonicalPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json);
    }
}
