<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class OccupancyPricingConfiguration
{
    public int $version;
    public string $oneNightSurcharge;
    public string $tourismTaxPerPersonPerNight;
    /** @var list<OccupancyStayLengthBand> */
    public array $bands;
    /** @var list<OccupancyDateOverride> */
    public array $overrides;

    /** @param list<OccupancyStayLengthBand> $bands @param list<OccupancyDateOverride> $overrides */
    public function __construct(int $version, string $oneNightSurcharge, array $bands, array $overrides = [], string $tourismTaxPerPersonPerNight = '0.00')
    {
        $ranges = [];
        foreach ($bands as $band) {
            if (!$band instanceof OccupancyStayLengthBand) throw new \InvalidArgumentException('Érvénytelen létszám ársáv.');
            if (!$band->active) continue;
            $end = $band->maxNights ?? PHP_INT_MAX;
            foreach ($ranges[$band->guestCount] ?? [] as [$from,$to]) if ($band->minNights <= $to && $from <= $end) throw new \InvalidArgumentException('Ez az ársáv átfedésben van egy már aktív, azonos létszámhoz tartozó ársávval. Előbb módosítsa vagy inaktiválja a meglévő ársávot.');
            $ranges[$band->guestCount][] = [$band->minNights,$end];
        }
        $active = [];
        foreach ($overrides as $override) {
            if (!$override instanceof OccupancyDateOverride) throw new \InvalidArgumentException('Érvénytelen időszakos ár.');
            if (!$override->active) continue;
            foreach ($active as $other) if ($override->startDate <= $other->endDate && $other->startDate <= $override->endDate) throw new \InvalidArgumentException('Az aktív egyedi időszakok nem fedhetik át egymást.');
            $active[] = $override;
        }
        $this->oneNightSurcharge = WholeHuf::normalize($oneNightSurcharge);
        $this->tourismTaxPerPersonPerNight = WholeHuf::normalize($tourismTaxPerPersonPerNight);
        $this->version = $version;
        $this->bands = $bands;
        $this->overrides = $overrides;
    }
    public function bandFor(int $guestCount, int $nights): OccupancyStayLengthBand
    { foreach ($this->bands as $band) if ($band->matches($guestCount,$nights)) return $band; throw new PricingConfigurationError('Erre a létszámra és tartózkodási időre jelenleg nincs ár beállítva.'); }
    public function overrideForNight(string $date): ?OccupancyDateOverride
    { foreach ($this->overrides as $override) if ($override->covers($date)) return $override; return null; }
}
