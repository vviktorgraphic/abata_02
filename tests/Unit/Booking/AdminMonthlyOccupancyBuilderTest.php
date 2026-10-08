<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Application\Booking\AdminMonthlyOccupancyBuilder;
use App\Application\Booking\AdminMonthlyOccupancyQuery;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AdminMonthlyOccupancyBuilderTest extends TestCase
{
    public function test_builds_every_day_with_half_open_booking_boundaries_and_turnarounds(): void
    {
        $query = new AdminMonthlyOccupancyQuery('2026-10', new DateTimeImmutable('2026-10-08 12:00:00+02:00'));
        $result = (new AdminMonthlyOccupancyBuilder())->build($query, [
            $this->booking('OLD', 'confirmed', '2026-09-29', '2026-10-01'),
            $this->booking('PENDING-LONG', 'pending', '2026-10-20', '2026-10-23'),
            $this->booking('A', 'pending', '2026-10-23', '2026-10-24', true),
            $this->booking('B', 'confirmed', '2026-10-24', '2026-10-27'),
            $this->booking('IGNORED', 'rejected', '2026-10-24', '2026-10-26'),
            $this->booking('CANCELLED', 'cancelled', '2026-10-24', '2026-10-26'),
            $this->booking('INVALIDATED', 'invalidated', '2026-10-24', '2026-10-26'),
            $this->booking('CROSS', 'confirmed', '2026-10-31', '2026-11-02'),
        ], []);

        self::assertCount(31, $result['days']);
        self::assertSame('Csütörtök', $this->day($result, '2026-10-01')['weekday']);
        self::assertTrue($this->day($result, '2026-10-08')['is_today']);
        self::assertSame(['free', 'departure'], $this->badgeKeys($this->day($result, '2026-10-01')));
        self::assertSame(['occupied'], $this->badgeKeys($this->day($result, '2026-10-21')));
        self::assertSame(['arrival', 'departure'], $this->badgeKeys($this->day($result, '2026-10-23')));
        self::assertSame(['arrival', 'departure'], $this->badgeKeys($this->day($result, '2026-10-24')));
        self::assertSame(['A', 'B'], array_column($this->day($result, '2026-10-24')['bookings'], 'reference'));
        self::assertSame(['occupied'], $this->badgeKeys($this->day($result, '2026-10-25')));
        self::assertSame(['free', 'departure'], $this->badgeKeys($this->day($result, '2026-10-27')));
        self::assertSame(['arrival'], $this->badgeKeys($this->day($result, '2026-10-31')));
        $turnaroundBookings = $this->day($result, '2026-10-23')['bookings'];
        $legacyIndex = array_search('A', array_column($turnaroundBookings, 'reference'), true);
        self::assertIsInt($legacyIndex);
        self::assertTrue($turnaroundBookings[$legacyIndex]['legacy']);
        self::assertNotContains('IGNORED', array_column($this->day($result, '2026-10-24')['bookings'], 'reference'));
        self::assertNotContains('CANCELLED', array_column($this->day($result, '2026-10-24')['bookings'], 'reference'));
        self::assertNotContains('INVALIDATED', array_column($this->day($result, '2026-10-24')['bookings'], 'reference'));
    }

    public function test_renders_manual_and_external_blocks_with_half_open_boundaries(): void
    {
        $query = new AdminMonthlyOccupancyQuery('2026-10', new DateTimeImmutable('2026-10-08'));
        $result = (new AdminMonthlyOccupancyBuilder())->build($query, [], [
            ['id' => 1, 'start_date' => '2026-10-10', 'end_date' => '2026-10-12', 'reason' => 'Karbantartás', 'external_event_id' => null],
            ['id' => 2, 'start_date' => '2026-10-11', 'end_date' => '2026-10-13', 'reason' => 'Imported', 'external_event_id' => 44, 'event_summary' => 'Partner foglalás', 'source_name' => 'Szállás.hu', 'provider' => 'szallas_hu'],
            ['id' => 3, 'start_date' => '2026-09-30', 'end_date' => '2026-10-02', 'reason' => 'Hónaphatár', 'external_event_id' => null],
        ]);

        self::assertSame(['blocked'], $this->badgeKeys($this->day($result, '2026-10-10')));
        self::assertSame(['blocked', 'external'], $this->badgeKeys($this->day($result, '2026-10-11')));
        self::assertSame(['external'], $this->badgeKeys($this->day($result, '2026-10-12')));
        self::assertSame(['free'], $this->badgeKeys($this->day($result, '2026-10-13')));
        self::assertSame('Szallas.hu', $this->day($result, '2026-10-11')['blocks'][1]['provider_label']);
        self::assertSame(['blocked'], $this->badgeKeys($this->day($result, '2026-10-01')));
        self::assertSame(['free'], $this->badgeKeys($this->day($result, '2026-10-02')));
    }

    /** @return array<string, mixed> */
    private function booking(string $reference, string $status, string $arrival, string $departure, bool $legacy = false): array
    {
        return compact('reference', 'status', 'arrival', 'departure', 'legacy') + [
            'arrival_date' => $arrival, 'departure_date' => $departure, 'contact_name' => 'Teszt Vendég',
        ];
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function day(array $result, string $date): array
    {
        foreach ($result['days'] as $day) if ($day['date'] === $date) return $day;
        self::fail('Day not found: ' . $date);
    }

    /** @param array<string, mixed> $day @return list<string> */
    private function badgeKeys(array $day): array { return array_column($day['badges'], 'key'); }
}
