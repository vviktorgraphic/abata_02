<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingStatusMailData
{
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public string $recipient,
        public string $status,
        public string $reference,
        public string $arrivalDate,
        public string $departureDate,
        public int $adults,
        public int $children,
        public string $totalAmount,
        public string $currency,
        public ?string $cancellationPenaltyAmount = null,
        public ?string $cancellationAccommodationFee = null,
    ) {
        if (!in_array($status, [self::CONFIRMED, self::REJECTED, self::CANCELLED], true)) {
            throw new \InvalidArgumentException('Unsupported booking notification status.');
        }
    }

    public function cancellationHasPenalty(): bool
    {
        return $this->cancellationPenaltyAmount !== null && $this->cancellationPenaltyAmount !== '0.00';
    }

    public function nights(): int
    {
        $timezone = new \DateTimeZone('Europe/Budapest');
        $arrival = new \DateTimeImmutable($this->arrivalDate, $timezone);
        $departure = new \DateTimeImmutable($this->departureDate, $timezone);

        return (int) $arrival->diff($departure)->days;
    }
}
