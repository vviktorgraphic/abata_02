<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\AdminMonthlyOccupancyBuilder;
use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Application\Booking\AdminMonthlyOccupancyRepository;
use PDO;

final readonly class PdoAdminMonthlyOccupancyRepository implements AdminMonthlyOccupancyRepository
{
    public const QUERY_COUNT = 2;

    public function __construct(private PDO $pdo, private AdminMonthlyOccupancyBuilder $builder = new AdminMonthlyOccupancyBuilder())
    {
    }

    public function fetch(AdminMonthlyOccupancyQuery $query): array
    {
        $statusPlaceholders = implode(', ', array_fill(0, count(AdminMonthlyOccupancyBuilder::VISIBLE_BOOKING_STATUSES), '?'));
        $bookings = $this->pdo->prepare(
            "SELECT b.reference, b.guest_name AS contact_name, b.status, b.arrival_date, b.departure_date,
                    CASE WHEN li.id IS NULL THEN 0 ELSE 1 END AS legacy
             FROM bookings b
             LEFT JOIN legacy_booking_imports li ON li.booking_id = b.id
             WHERE b.status IN ({$statusPlaceholders})
               AND b.arrival_date < ? AND b.departure_date >= ?
             ORDER BY b.arrival_date, b.departure_date, b.id"
        );
        $bookings->execute([
            ...AdminMonthlyOccupancyBuilder::VISIBLE_BOOKING_STATUSES,
            $query->nextMonthStart->format('Y-m-d'),
            $query->monthStart->format('Y-m-d'),
        ]);

        $blocks = $this->pdo->prepare(
            'SELECT bp.id, bp.start_date, bp.end_date, bp.reason,
                    e.id AS external_event_id, e.summary AS event_summary,
                    cs.name AS source_name, cs.provider
             FROM blocked_periods bp
             LEFT JOIN (
                 SELECT blocked_period_id, MIN(id) AS event_id
                 FROM external_calendar_events
                 WHERE blocked_period_id IS NOT NULL
                 GROUP BY blocked_period_id
             ) linked ON linked.blocked_period_id = bp.id
             LEFT JOIN external_calendar_events e ON e.id = linked.event_id
             LEFT JOIN calendar_sources cs ON cs.id = e.calendar_source_id
             WHERE bp.is_active = TRUE
               AND bp.start_date < ? AND bp.end_date > ?
             ORDER BY bp.start_date, bp.end_date, bp.id'
        );
        $blocks->execute([
            $query->nextMonthStart->format('Y-m-d'),
            $query->monthStart->format('Y-m-d'),
        ]);

        return $this->builder->build($query, $bookings->fetchAll(), $blocks->fetchAll());
    }
}
