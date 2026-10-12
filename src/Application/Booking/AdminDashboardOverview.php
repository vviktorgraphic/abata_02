<?php

declare(strict_types=1);

namespace App\Application\Booking;

final readonly class AdminDashboardOverview
{
    /**
     * @param list<array{reference: string, contact_name: string, arrival_date: string, departure_date: string, nights: int, adults: int, children: int, party_size: int}> $arrivalsToday
     * @param list<array{reference: string, contact_name: string, arrival_date: string, departure_date: string, nights: int, adults: int, children: int, party_size: int}> $departuresToday
     * @param list<array{reference: string, contact_name: string, arrival_date: string, departure_date: string, nights: int, adults: int, children: int, party_size: int}> $upcomingArrivals
     */
    public function __construct(
        public int $pendingCount,
        public int $arrivalsTodayCount,
        public int $departuresTodayCount,
        public int $currentGuestsCount,
        public array $arrivalsToday,
        public array $departuresToday,
        public array $upcomingArrivals,
        public bool $hasMoreUpcoming,
    ) {
    }
}
