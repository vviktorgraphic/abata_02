<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Calendar;

use App\Application\Calendar\CalendarSourceRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class PdoCalendarSourceRepository implements CalendarSourceRepository
{
    private const PROVIDERS = ['google_calendar', 'szallas_hu'];
    private const DIRECTIONS = ['import', 'export', 'bidirectional'];

    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query(
            'SELECT id, name, provider, url, direction, enabled, last_success_at, last_error_at, created_at, updated_at
             FROM calendar_sources ORDER BY name, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, provider, url, direction, enabled, last_success_at, last_error_at, created_at, updated_at
             FROM calendar_sources WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(string $name, string $provider, string $url, string $direction, bool $enabled, ?string $syncToken = null): int
    {
        $this->validate($name, $provider, $url, $direction);
        $statement = $this->pdo->prepare(
            'INSERT INTO calendar_sources (name, provider, url, direction, enabled, sync_token_hash)
             VALUES (:name, :provider, :url, :direction, :enabled, :sync_token_hash)'
        );
        $statement->execute([
            'name' => trim($name), 'provider' => $provider, 'url' => trim($url), 'direction' => $direction,
            'enabled' => $enabled ? 1 : 0, 'sync_token_hash' => self::hashNullable($syncToken),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $provider, string $url, string $direction, bool $enabled, ?string $syncToken = null): void
    {
        $this->validate($name, $provider, $url, $direction);
        $sql = 'UPDATE calendar_sources SET name = :name, provider = :provider, url = :url,
                direction = :direction, enabled = :enabled';
        $parameters = ['id' => $id, 'name' => trim($name), 'provider' => $provider, 'url' => trim($url),
            'direction' => $direction, 'enabled' => $enabled ? 1 : 0];
        if ($syncToken !== null && $syncToken !== '') {
            $sql .= ', sync_token_hash = :sync_token_hash';
            $parameters['sync_token_hash'] = hash('sha256', $syncToken);
        }
        $statement = $this->pdo->prepare($sql . ' WHERE id = :id');
        $statement->execute($parameters);
    }

    public function delete(int $id): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Calendar source deletion owns its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
            $deactivate = $this->pdo->prepare(
                'UPDATE blocked_periods bp INNER JOIN external_calendar_events e ON e.blocked_period_id = bp.id
                 SET bp.is_active = FALSE, bp.removed_at = COALESCE(bp.removed_at, CURRENT_TIMESTAMP)
                 WHERE e.calendar_source_id = :id AND bp.is_active = TRUE'
            );
            $deactivate->execute(['id' => $id]);
            $statement = $this->pdo->prepare('DELETE FROM calendar_sources WHERE id = :id');
            $statement->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function markSuccess(int $id, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare('UPDATE calendar_sources SET last_success_at = :at WHERE id = :id');
        $statement->execute(['at' => $at->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s'), 'id' => $id]);
    }

    public function markError(int $id, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare('UPDATE calendar_sources SET last_error_at = :at WHERE id = :id');
        $statement->execute(['at' => $at->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s'), 'id' => $id]);
    }

    private function validate(string $name, string $provider, string $url, string $direction): void
    {
        if (trim($name) === '' || mb_strlen(trim($name)) > 150 || !in_array($provider, self::PROVIDERS, true)
            || !in_array($direction, self::DIRECTIONS, true) || filter_var(trim($url), FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Invalid calendar source configuration.');
        }
    }

    private static function hashNullable(?string $token): ?string
    {
        return $token === null || $token === '' ? null : hash('sha256', $token);
    }
}
