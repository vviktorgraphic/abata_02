<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PersonPricingConfiguration
{
    public ?string $adultWeekdayPrice;
    public ?string $adultWeekendPrice;

    /** @param list<ChildPriceBand> $childBands */
    public function __construct(
        public int $version = 1,
        public string $mode = 'legacy',
        ?string $adultWeekdayPrice = null,
        ?string $adultWeekendPrice = null,
        public array $childBands = [],
    ) {
        if ($version < 1 || !in_array($mode, ['legacy', 'person'], true)) {
            throw new \InvalidArgumentException('Érvénytelen árképzési mód vagy verzió.');
        }
        $this->adultWeekdayPrice = $adultWeekdayPrice === null ? null : WholeHuf::normalize($adultWeekdayPrice);
        $this->adultWeekendPrice = $adultWeekendPrice === null ? null : WholeHuf::normalize($adultWeekendPrice);
        $covered = [];
        $ids = [];
        foreach ($childBands as $band) {
            if (!$band instanceof ChildPriceBand) {
                throw new \InvalidArgumentException('Érvénytelen gyermek ársáv.');
            }
            if ($band->id !== 0 && isset($ids[$band->id])) {
                throw new \InvalidArgumentException('Egy gyermek ársáv csak egyszer szerepelhet.');
            }
            $ids[$band->id] = true;
            if (!$band->active) { continue; }
            foreach (range($band->minAge, $band->maxAge) as $age) {
                if (isset($covered[$age])) {
                    throw new \InvalidArgumentException('Az aktív gyermek ársávok nem fedhetik át egymást.');
                }
                $covered[$age] = true;
            }
        }
    }

    public function bandForAge(int $age): ChildPriceBand
    {
        foreach ($this->childBands as $band) {
            if ($band->active && $age >= $band->minAge && $age <= $band->maxAge) { return $band; }
        }
        throw new MissingChildPriceBand('A megadott gyermekéletkorhoz nincs aktív ársáv.');
    }
}
