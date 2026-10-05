<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class OccupancyDateOverride
{
    /** @var array<int,string> */
    public array $prices;
    public function __construct(public int $id, public string $startDate, public string $endDate, array $prices, public bool $active = true)
    {
        foreach ([$startDate, $endDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Az időszak dátuma érvénytelen.');
        }
        if ($startDate > $endDate) throw new \InvalidArgumentException('A kezdő dátum nem lehet későbbi a záró dátumnál.');
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
    public function snapshot(): array { return ['id'=>$this->id,'start_date'=>$this->startDate,'end_date'=>$this->endDate,'prices'=>$this->prices,'is_active'=>$this->active]; }
}
