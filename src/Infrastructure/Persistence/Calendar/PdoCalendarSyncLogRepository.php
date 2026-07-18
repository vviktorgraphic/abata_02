<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Calendar;

use App\Application\Calendar\CalendarSyncLogRepository;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;

final readonly class PdoCalendarSyncLogRepository implements CalendarSyncLogRepository
{
    private const STATUSES = ['success', 'warning', 'failed'];

    public function __construct(private PDO $pdo)
    {
    }

    public function start(int $sourceId, DateTimeImmutable $startedAt): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO calendar_sync_logs (calendar_source_id, status, started_at, warnings_json, errors_json)
             VALUES (:source_id, 'running', :started_at, JSON_ARRAY(), JSON_ARRAY())"
        );
        $statement->execute(['source_id' => $sourceId, 'started_at' => self::timestamp($startedAt)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $id, string $status, DateTimeImmutable $finishedAt, int $imported, int $exported, array $warnings, array $errors): void
    {
        if (!in_array($status, self::STATUSES, true) || $imported < 0 || $exported < 0) {
            throw new \InvalidArgumentException('Invalid calendar sync result.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE calendar_sync_logs SET status = :status, finished_at = :finished_at,
                imported_count = :imported, exported_count = :exported, warnings_json = :warnings, errors_json = :errors
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status, 'finished_at' => self::timestamp($finishedAt), 'imported' => $imported, 'exported' => $exported,
            'warnings' => self::safeMessages($warnings), 'errors' => self::safeMessages($errors), 'id' => $id,
        ]);
    }

    public function recent(?int $sourceId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($sourceId === null) {
            return $this->pdo->query(
                "SELECT id, calendar_source_id, status, started_at, finished_at, imported_count, exported_count,
                        warnings_json, errors_json FROM calendar_sync_logs ORDER BY started_at DESC, id DESC LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $statement = $this->pdo->prepare(
            "SELECT id, calendar_source_id, status, started_at, finished_at, imported_count, exported_count,
                    warnings_json, errors_json FROM calendar_sync_logs WHERE calendar_source_id = :source_id
             ORDER BY started_at DESC, id DESC LIMIT {$limit}"
        );
        $statement->execute(['source_id' => $sourceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function timestamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
    }

    /** @param list<string> $messages */
    private static function safeMessages(array $messages): string
    {
        foreach ($messages as $message) {
            if (filter_var($message, FILTER_VALIDATE_URL) !== false || preg_match('~https?://~i', $message)) {
                throw new \InvalidArgumentException('Calendar sync logs must not contain URLs.');
            }
        }
        try {
            return json_encode(array_values($messages), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new \InvalidArgumentException('Calendar sync log messages are not encodable.', 0, $error);
        }
    }
}
