<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class OccupancyStayLengthBand
{
    public string $nightlyPrice;
    public function __construct(
        public int $id,
        public int $guestCount,
        public int $minNights,
        public ?int $maxNights,
        string $nightlyPrice,
        public bool $active = true,
        public int $sortOrder = 0,
    ) {
        if ($guestCount < 1 || $guestCount > 4 || $minNights < 1 || ($maxNights !== null && $maxNights < $minNights)) {
            throw new \InvalidArgumentException('Érvénytelen létszám- vagy éjszakasáv.');
        }
        $this->nightlyPrice = WholeHuf::normalize($nightlyPrice);
    }

    public function matches(int $guestCount, int $nights): bool
    { return $this->active && $this->guestCount === $guestCount && $nights >= $this->minNights && ($this->maxNights === null || $nights <= $this->maxNights); }

    /** @return array<string,mixed> */
    public function snapshot(): array
    { return ['id'=>$this->id,'guest_count'=>$this->guestCount,'min_nights'=>$this->minNights,'max_nights'=>$this->maxNights,'nightly_price'=>$this->nightlyPrice,'is_active'=>$this->active]; }
}
