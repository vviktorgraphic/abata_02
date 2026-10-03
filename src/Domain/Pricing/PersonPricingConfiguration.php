<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PersonPricingConfiguration
{
    public ?string $adultWeekdayPrice;
    public ?string $adultWeekendPrice;

    /** @param list<ChildPriceBand> $childBands @param list<AdultStayLengthBand> $adultStayLengthBands */
    public function __construct(
        public int $version = 1,
        public string $mode = 'legacy',
        ?string $adultWeekdayPrice = null,
        ?string $adultWeekendPrice = null,
        public array $childBands = [],
        public array $adultStayLengthBands = [],
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
        $ranges = [];
        foreach ($adultStayLengthBands as $band) {
            if (!$band instanceof AdultStayLengthBand) { throw new \InvalidArgumentException('Érvénytelen felnőtt tartózkodási ársáv.'); }
            if ($band->id !== 0 && isset($ids['adult'.$band->id])) { throw new \InvalidArgumentException('Egy tartózkodási ársáv csak egyszer szerepelhet.'); }
            $ids['adult'.$band->id] = true;
            if (!$band->active) { continue; }
            $end = $band->maxNights ?? PHP_INT_MAX;
            foreach ($ranges as [$from, $to]) { if ($band->minNights <= $to && $from <= $end) { throw new \InvalidArgumentException('Az aktív tartózkodási ársávok nem fedhetik át egymást.'); } }
            $ranges[] = [$band->minNights, $end];
        }
    }

    public function bandForAge(int $age): ChildPriceBand
    {
        foreach ($this->childBands as $band) {
            if ($band->active && $age >= $band->minAge && $age <= $band->maxAge) { return $band; }
        }
        throw new MissingChildPriceBand('A megadott gyermekéletkorhoz nincs aktív ársáv.');
    }

    public function adultBandForNights(int $nights): ?AdultStayLengthBand
    {
        foreach ($this->adultStayLengthBands as $band) if ($band->matches($nights)) return $band;
        return null;
    }
}
