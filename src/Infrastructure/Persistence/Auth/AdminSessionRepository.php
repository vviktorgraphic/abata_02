<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class AdminSessionRepository
{
    public function __construct(private PDO $pdo, private int $absoluteLifetimeSeconds = 28800)
    {
        if ($this->absoluteLifetimeSeconds <= 0) {
            throw new \InvalidArgumentException('Absolute session lifetime must be positive.');
        }
    }

    public function create(
        int $adminId,
        string $rawSessionToken,
        string $authLevel,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $activityAt = null,
    ): void {
        $activityAt ??= $createdAt;
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_sessions
                (admin_id, session_token_hash, auth_level, created_at, last_activity_at, expires_at)
             VALUES
                (:admin_id, :token_hash, :auth_level, :created_at, :last_activity_at,
                 LEAST(:expires_at, DATE_ADD(:absolute_created_at, INTERVAL ' . $this->absoluteLifetimeSeconds . ' SECOND)))'
        );
        $statement->execute([
            'admin_id' => $adminId,
            'token_hash' => $this->hash($rawSessionToken),
            'auth_level' => $authLevel,
            'created_at' => $this->format($createdAt),
            'absolute_created_at' => $this->format($createdAt),
            'last_activity_at' => $this->format($activityAt),
            'expires_at' => $this->format($expiresAt),
        ]);
    }

    /** Refreshes the sliding idle expiry for an active session. */
    public function touch(string $rawSessionToken, DateTimeImmutable $activityAt, DateTimeImmutable $expiresAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_sessions
             SET last_activity_at = :activity_at,
                 expires_at = LEAST(:expires_at, DATE_ADD(created_at, INTERVAL ' . $this->absoluteLifetimeSeconds . ' SECOND))
             WHERE session_token_hash = :token_hash AND revoked_at IS NULL AND expires_at > :current_time
               AND DATE_ADD(created_at, INTERVAL ' . $this->absoluteLifetimeSeconds . ' SECOND) > :absolute_current_time'
        );
        $statement->execute([
            'activity_at' => $this->format($activityAt),
            'current_time' => $this->format($activityAt),
            'absolute_current_time' => $this->format($activityAt),
            'expires_at' => $this->format($expiresAt),
            'token_hash' => $this->hash($rawSessionToken),
        ]);
        return $statement->rowCount() === 1;
    }

    /** Returns the admin id only for a non-revoked, non-expired session at the required auth level. */
    public function activeAdminId(string $rawSessionToken, string $authLevel, DateTimeImmutable $now): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT admin_id FROM admin_sessions
             WHERE session_token_hash = :token_hash AND auth_level = :auth_level
               AND revoked_at IS NULL AND expires_at > :now
               AND DATE_ADD(created_at, INTERVAL ' . $this->absoluteLifetimeSeconds . ' SECOND) > :absolute_now LIMIT 1'
        );
        $statement->execute([
            'token_hash' => $this->hash($rawSessionToken),
            'auth_level' => $authLevel,
            'now' => $this->format($now),
            'absolute_now' => $this->format($now),
        ]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function revoke(string $rawSessionToken, DateTimeImmutable $revokedAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = :revoked_at
             WHERE session_token_hash = :token_hash AND revoked_at IS NULL'
        );
        $statement->execute([
            'revoked_at' => $this->format($revokedAt),
            'token_hash' => $this->hash($rawSessionToken),
        ]);

        return $statement->rowCount() === 1;
    }

    private function hash(string $rawSessionToken): string
    {
        return hash('sha256', $rawSessionToken);
    }

    /** Returns the immutable absolute-lifetime origin for an active session. */
    public function activeCreatedAt(string $rawSessionToken, string $authLevel, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $statement = $this->pdo->prepare(
            'SELECT created_at FROM admin_sessions
             WHERE session_token_hash = :token_hash AND auth_level = :auth_level
               AND revoked_at IS NULL AND expires_at > :now
               AND DATE_ADD(created_at, INTERVAL ' . $this->absoluteLifetimeSeconds . ' SECOND) > :absolute_now LIMIT 1'
        );
        $statement->execute([
            'token_hash' => $this->hash($rawSessionToken),
            'auth_level' => $authLevel,
            'now' => $this->format($now),
            'absolute_now' => $this->format($now),
        ]);
        $createdAt = $statement->fetchColumn();
        return $createdAt === false
            ? null
            : new DateTimeImmutable((string) $createdAt, new DateTimeZone('Europe/Budapest'));
    }

    private function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
    }
}
