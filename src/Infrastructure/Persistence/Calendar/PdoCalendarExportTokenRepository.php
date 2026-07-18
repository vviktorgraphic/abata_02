<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Calendar;

use App\Application\Calendar\CalendarExportTokenRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoCalendarExportTokenRepository implements CalendarExportTokenRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function rotate(string $plainToken, DateTimeImmutable $at): void
    {
        if (strlen($plainToken) < 32) {
            throw new \InvalidArgumentException('Calendar export token must contain at least 32 characters.');
        }
        $timestamp = $at->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO calendar_export_tokens (id, token_hash, created_at, rotated_at)
             VALUES (1, :hash, :created_at, NULL)
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), rotated_at = VALUES(created_at)'
        );
        $statement->execute(['hash' => hash('sha256', $plainToken), 'created_at' => $timestamp]);
    }

    public function verify(string $plainToken): bool
    {
        if ($plainToken === '') {
            return false;
        }
        $stored = $this->pdo->query('SELECT token_hash FROM calendar_export_tokens WHERE id = 1')->fetchColumn();
        return is_string($stored) && hash_equals($stored, hash('sha256', $plainToken));
    }

    public function metadata(): ?array
    {
        $row = $this->pdo->query('SELECT created_at, rotated_at FROM calendar_export_tokens WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['created_at' => (string) $row['created_at'], 'rotated_at' => $row['rotated_at'] === null ? null : (string) $row['rotated_at']];
    }
}
