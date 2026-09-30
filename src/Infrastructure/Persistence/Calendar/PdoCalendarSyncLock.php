<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence\Calendar;

use App\Application\Calendar\CalendarSyncLock;
use PDO;

/** Connection-owned advisory locks: MySQL releases abandoned locks on disconnect. */
final class PdoCalendarSyncLock implements CalendarSyncLock
{
    private array $held = [];
    private string $namespace;

    public function __construct(private PDO $pdo)
    {
        $this->namespace = substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
    }

    public function acquire(int $sourceId): bool
    {
        if (isset($this->held[$sourceId])) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => $this->name($sourceId)]);
        $result = $statement->fetchColumn();
        if ($result === null || $result === false) {
            throw new \RuntimeException('Calendar lock unavailable.');
        }
        if ((int) $result !== 1) {
            return false;
        }
        $this->held[$sourceId] = true;
        return true;
    }

    public function release(int $sourceId): void
    {
        if (isset($this->held[$sourceId])) {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => $this->name($sourceId)]);
            unset($this->held[$sourceId]);
        }
    }

    private function name(int $sourceId): string
    {
        return 'ical:' . $this->namespace . ':' . $sourceId;
    }
}
