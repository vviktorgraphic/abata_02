<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class OccupancyDateOverride
{
    public const MAX_STAY_NIGHTS = 30;

    /** @var array<int,string> */
    public array $prices;
    public function __construct(
        public int $id,
        public string $startDate,
        public string $endDate,
        array $prices,
        public bool $active = true,
        public int $minNights = 1,
        public ?int $maxNights = null,
    )
    {
        foreach ([$startDate, $endDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Az időszak dátuma érvénytelen.');
        }
        if ($startDate > $endDate) throw new \InvalidArgumentException('A kezdő dátum nem lehet későbbi a záró dátumnál.');
        if ($minNights < 1 || $minNights > self::MAX_STAY_NIGHTS
            || ($maxNights !== null && ($maxNights < $minNights || $maxNights > self::MAX_STAY_NIGHTS))) {
            throw new \InvalidArgumentException('Az időszak minimum és maximum éjszakaszáma 1 és 30 között, egymással összhangban adható meg.');
        }
        $normalizedPrices = [];
        for ($i=1; $i<=4; $i++) {
            if (!array_key_exists($i, $prices)) throw new \InvalidArgumentException('Mind a négy létszám ára kötelező.');
            $normalizedPrices[$i] = WholeHuf::normalize((string) $prices[$i]);
        }
        $this->prices = $normalizedPrices;
    }
    public function covers(string $date): bool { return $this->active && $date >= $this->startDate && $date <= $this->endDate; }
    public function priceFor(int $guestCount): string { return $this->prices[$guestCount] ?? throw new \InvalidArgumentException('Érvénytelen árazási létszám.'); }
    /** @return array<string,mixed> */
    public function snapshot(): array { return ['id'=>$this->id,'start_date'=>$this->startDate,'end_date'=>$this->endDate,'min_nights'=>$this->minNights,'max_nights'=>$this->maxNights,'prices'=>$this->prices,'is_active'=>$this->active]; }
}
