<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditMetadata;
use DateTimeImmutable;

final class AdminBookingNotificationAuditEvents
{
    /**
     * @param list<array{id:int,old:bool,new:bool}> $changes
     * @return list<AuditEvent>
     */
    public static function forChanges(array $changes, int $actorId, DateTimeImmutable $occurredAt): array
    {
        return array_map(static fn (array $change): AuditEvent => new AuditEvent(
            'admin_user.booking_notifications_' . ($change['new'] ? 'enabled' : 'disabled'),
            'success',
            $occurredAt,
            new AuditMetadata([
                'target_type' => 'admin',
                'target_id' => (string) $change['id'],
                'target_admin_id' => $change['id'],
            ]),
            $actorId,
        ), $changes);
    }
}
