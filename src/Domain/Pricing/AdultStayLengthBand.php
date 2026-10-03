<?php
declare(strict_types=1);
namespace App\Domain\Pricing;

final readonly class AdultStayLengthBand
{
    public string $pricePerPersonPerNight;
    public function __construct(
        public int $minNights,
        public ?int $maxNights,
        string $pricePerPersonPerNight,
        public bool $active = true,
        public int $sortOrder = 0,
        public int $id = 0,
    ) {
        if ($minNights < 1 || ($maxNights !== null && $maxNights < $minNights) || $id < 0) {
            throw new \InvalidArgumentException('Érvénytelen tartózkodási ársáv.');
        }
        $this->pricePerPersonPerNight = WholeHuf::normalize($pricePerPersonPerNight);
    }
    public function matches(int $nights): bool { return $this->active && $nights >= $this->minNights && ($this->maxNights === null || $nights <= $this->maxNights); }
    /** @return array<string,mixed> */
    public function snapshot(): array { return ['id'=>$this->id,'min_nights'=>$this->minNights,'max_nights'=>$this->maxNights,'price_per_person_per_night'=>$this->pricePerPersonPerNight,'active'=>$this->active,'sort_order'=>$this->sortOrder]; }
}
