<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class ChildPriceBand
{
    public string $weekdayPrice;
    public string $weekendPrice;

    public function __construct(
        public int $minAge,
        public int $maxAge,
        string $weekdayPrice,
        string $weekendPrice,
        public bool $active = true,
        public int $sortOrder = 0,
        public int $id = 0,
    ) {
        if ($minAge < 0 || $maxAge < $minAge || $maxAge > 17 || $sortOrder < 0 || $id < 0) {
            throw new \InvalidArgumentException('A gyermek ársáv határai 0–17 év között lehetnek; a minimum nem lehet nagyobb a maximumnál.');
        }
        $this->weekdayPrice = WholeHuf::normalize($weekdayPrice);
        $this->weekendPrice = WholeHuf::normalize($weekendPrice);
    }

    /** @return array<string, int|string|bool> */
    public function snapshot(): array
    {
        return ['id'=>$this->id, 'min_age'=>$this->minAge, 'max_age'=>$this->maxAge,
            'weekday_price'=>$this->weekdayPrice, 'weekend_price'=>$this->weekendPrice,
            'active'=>$this->active, 'sort_order'=>$this->sortOrder];
    }
}
