<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Application\Authentication\AdminBookingNotificationAuditEvents;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AdminBookingNotificationAuditEventsTest extends TestCase
{
    public function test_only_actual_changes_create_semantic_audit_events(): void
    {
        $now = new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('Europe/Budapest'));
        self::assertSame([], AdminBookingNotificationAuditEvents::forChanges([], 9, $now));

        $events = AdminBookingNotificationAuditEvents::forChanges([
            ['id'=>2,'old'=>false,'new'=>true],
            ['id'=>3,'old'=>true,'new'=>false],
        ], 9, $now);
        self::assertSame('admin_user.booking_notifications_enabled', $events[0]->eventType);
        self::assertSame('admin_user.booking_notifications_disabled', $events[1]->eventType);
        self::assertSame(9, $events[0]->adminId);
        self::assertSame(['target_type'=>'admin','target_id'=>'2','target_admin_id'=>2], $events[0]->metadata->values);
    }
}
