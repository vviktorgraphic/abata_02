<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingStatus;
use DateTimeImmutable;
use DateTimeZone;

final class AdminMonthlyOccupancyBuilder
{
    /**
     * @param list<array<string, mixed>> $bookings
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    public function build(AdminMonthlyOccupancyQuery $query, array $bookings, array $blocks): array
    {
        $days = [];
        foreach ($query->dayDates() as $date) {
            $day = $this->date($date);
            $days[$date] = [
                'date' => $date,
                'display_date' => $day->format('Y.m.d'),
                'weekday' => $this->weekday((int) $day->format('N')),
                'is_today' => $date === $query->today,
                'is_weekend' => (int) $day->format('N') >= 6,
                'bookings' => [],
                'blocks' => [],
                'badges' => [],
                'is_free' => true,
            ];
        }

        foreach ($bookings as $booking) {
            $status = BookingStatus::tryFrom((string) ($booking['status'] ?? ''));
            if ($status === null || !$status->blocksPublicBooking()) {
                continue;
            }
            $arrival = $this->date((string) ($booking['arrival_date'] ?? ''));
            $departure = $this->date((string) ($booking['departure_date'] ?? ''));
            if ($arrival >= $departure) {
                throw new \RuntimeException('Stored booking dates are invalid.');
            }
            $first = $arrival > $query->monthStart ? $arrival : $query->monthStart;
            for ($day = $first; $day < $query->nextMonthStart && $day <= $departure; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                if (!isset($days[$date])) {
                    continue;
                }
                $event = $day == $arrival ? 'arrival' : ($day == $departure ? 'departure' : 'occupied');
                $days[$date]['bookings'][] = [
                    'reference' => (string) ($booking['reference'] ?? ''),
                    'contact_name' => (string) ($booking['contact_name'] ?? ''),
                    'status' => $status->value,
                    'event' => $event,
                    'event_label' => ['arrival' => 'érkezés', 'departure' => 'távozás', 'occupied' => 'foglalt'][$event],
                    'legacy' => (bool) ($booking['legacy'] ?? false),
                ];
            }
        }

        foreach ($blocks as $block) {
            $start = $this->date((string) ($block['start_date'] ?? ''));
            $end = $this->date((string) ($block['end_date'] ?? ''));
            if ($start >= $end) {
                throw new \RuntimeException('Stored blocked-period dates are invalid.');
            }
            $first = $start > $query->monthStart ? $start : $query->monthStart;
            $external = ($block['external_event_id'] ?? null) !== null;
            $summary = $block['event_summary'] ?? null;
            $sourceName = $block['source_name'] ?? null;
            for ($day = $first; $day < $end && $day < $query->nextMonthStart; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                if (!isset($days[$date])) {
                    continue;
                }
                $days[$date]['blocks'][] = [
                    'id' => (int) ($block['id'] ?? 0),
                    'kind' => $external ? 'external' : 'manual',
                    'reason' => (string) ($block['reason'] ?? ''),
                    'summary' => $summary !== null ? (string) $summary : null,
                    'source_name' => $sourceName !== null ? (string) $sourceName : null,
                    'provider_label' => $external ? $this->providerLabel((string) ($block['provider'] ?? '')) : null,
                ];
            }
        }

        foreach ($days as &$day) {
            $events = array_column($day['bookings'], 'event');
            $blockKinds = array_column($day['blocks'], 'kind');
            $hasArrival = in_array('arrival', $events, true);
            $hasOccupied = in_array('occupied', $events, true);
            $hasDeparture = in_array('departure', $events, true);
            $hasManual = in_array('manual', $blockKinds, true);
            $hasExternal = in_array('external', $blockKinds, true);
            $day['is_free'] = !$hasArrival && !$hasOccupied && !$hasManual && !$hasExternal;
            if ($day['is_free']) $day['badges'][] = ['key' => 'free', 'label' => 'Szabad'];
            if ($hasArrival) $day['badges'][] = ['key' => 'arrival', 'label' => 'Érkezés'];
            if ($hasOccupied) $day['badges'][] = ['key' => 'occupied', 'label' => 'Foglalt'];
            if ($hasDeparture) $day['badges'][] = ['key' => 'departure', 'label' => 'Távozás'];
            if ($hasManual) $day['badges'][] = ['key' => 'blocked', 'label' => 'Blokkolt'];
            if ($hasExternal) $day['badges'][] = ['key' => 'external', 'label' => 'Külső naptár'];
        }
        unset($day);

        return [
            'month' => $query->month,
            'label' => $query->label,
            'previous_month' => $query->previousMonth,
            'next_month' => $query->nextMonth,
            'current_month' => $query->currentMonth,
            'is_current_month' => $query->month === $query->currentMonth,
            'days' => array_values($days),
        ];
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(AdminMonthlyOccupancyQuery::TIMEZONE));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException('Stored calendar date is invalid.');
        }

        return $date;
    }

    private function weekday(int $day): string
    {
        return [1 => 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'][$day];
    }

    private function providerLabel(string $provider): string
    {
        return ['szallas_hu' => 'Szallas.hu', 'google_calendar' => 'Google Calendar'][$provider] ?? 'Külső naptár';
    }
}
