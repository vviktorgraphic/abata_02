<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Application\Audit\AuditLog;
use App\Application\Authentication\AdminBookingNotificationAuditEvents;
use App\Application\Authentication\AdminBookingNotificationPreferenceRepository;
use DateTimeImmutable;
use PDO;

final readonly class PdoAdminBookingNotificationPreferenceRepository implements AdminBookingNotificationPreferenceRepository
{
    public function __construct(private PDO $pdo, private AuditLog $audit)
    {
    }

    public function replaceBookingNotificationRecipients(
        array $selectedAdminIds,
        int $actorAdminId,
        DateTimeImmutable $occurredAt,
    ): array {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('A foglalási értesítési beállítások mentése saját tranzakciót igényel.');
        }

        $selected = array_fill_keys($selectedAdminIds, true);
        $this->pdo->beginTransaction();
        try {
            $rows = $this->pdo->query(
                'SELECT id, receives_booking_notifications FROM admins ORDER BY id FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_fill_keys(array_map(static fn (array $row): int => (int) $row['id'], $rows), true);
            if (array_diff_key($selected, $existing) !== []) {
                throw new \InvalidArgumentException('A kijelölt felhasználó nem található.');
            }

            $update = $this->pdo->prepare(
                'UPDATE admins SET receives_booking_notifications = :enabled WHERE id = :id'
            );
            $changes = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $old = (bool) $row['receives_booking_notifications'];
                $new = isset($selected[$id]);
                if ($old === $new) {
                    continue;
                }
                $update->execute(['enabled' => $new ? 1 : 0, 'id' => $id]);
                $changes[] = ['id' => $id, 'old' => $old, 'new' => $new];
            }

            foreach (AdminBookingNotificationAuditEvents::forChanges($changes, $actorAdminId, $occurredAt) as $event) {
                $this->audit->append($event);
            }

            $this->pdo->commit();
            return $changes;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
