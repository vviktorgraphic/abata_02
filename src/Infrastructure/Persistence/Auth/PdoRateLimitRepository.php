<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Security\RateLimit\RateLimitRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoRateLimitRepository implements RateLimitRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function countFailures(string $scope, string $keyHash, DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE bucket_type = :scope AND bucket_hash = :key_hash
               AND succeeded = FALSE AND attempted_at >= :since'
        );
        $statement->execute(['scope' => $scope, 'key_hash' => $keyHash, 'since' => $this->format($since)]);
        return (int) $statement->fetchColumn();
    }

    public function recordFailure(string $scope, string $keyHash, DateTimeImmutable $occurredAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (bucket_type, bucket_hash, attempted_at, succeeded)
             VALUES (:scope, :key_hash, :attempted_at, FALSE)'
        );
        $statement->execute([
            'scope' => $scope,
            'key_hash' => $keyHash,
            'attempted_at' => $this->format($occurredAt),
        ]);
    }

    public function clearFailures(string $scope, string $keyHash): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE bucket_type = :scope AND bucket_hash = :key_hash'
        );
        $statement->execute(['scope' => $scope, 'key_hash' => $keyHash]);
    }

    public function lockedUntil(string $scope, string $keyHash): ?DateTimeImmutable
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(locked_until) FROM login_attempts
             WHERE bucket_type = :scope AND bucket_hash = :key_hash'
        );
        $statement->execute(['scope' => $scope, 'key_hash' => $keyHash]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null
            ? null
            : new DateTimeImmutable((string) $value, new DateTimeZone('Europe/Budapest'));
    }

    public function lock(string $scope, string $keyHash, DateTimeImmutable $until): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (bucket_type, bucket_hash, attempted_at, succeeded, locked_until)
             VALUES (:scope, :key_hash, :attempted_at, TRUE, :locked_until)'
        );
        $statement->execute([
            'scope' => $scope,
            'key_hash' => $keyHash,
            'attempted_at' => $this->format(new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'))),
            'locked_until' => $this->format($until),
        ]);
    }

    private function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
    }
}
