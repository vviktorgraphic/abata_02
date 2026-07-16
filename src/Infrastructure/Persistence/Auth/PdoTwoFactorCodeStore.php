<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Application\TwoFactor\TwoFactorCodeStore;
use App\Application\TwoFactor\TwoFactorVerificationStore;
use App\Domain\TwoFactor\TwoFactorCode;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final readonly class PdoTwoFactorCodeStore implements TwoFactorCodeStore, TwoFactorVerificationStore
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function findActiveForUpdate(int $adminId): ?TwoFactorCode
    {
        $statement = $this->pdo->prepare(
            'SELECT code_hash, expires_at, attempt_count, used_at, invalidated_at
             FROM admin_login_codes WHERE admin_id = :admin_id
             ORDER BY sent_at DESC, id DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['admin_id' => $adminId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $timezone = new DateTimeZone('Europe/Budapest');
        return new TwoFactorCode(
            (string) $row['code_hash'],
            new DateTimeImmutable((string) $row['expires_at'], $timezone),
            (int) $row['attempt_count'],
            $row['used_at'] === null ? null : new DateTimeImmutable((string) $row['used_at'], $timezone),
            $row['invalidated_at'] === null ? null : new DateTimeImmutable((string) $row['invalidated_at'], $timezone),
        );
    }

    public function saveState(int $adminId, TwoFactorCode $code): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_login_codes
             SET attempt_count = :attempt_count, used_at = :used_at, invalidated_at = :invalidated_at
             WHERE admin_id = :admin_id AND code_hash = :code_hash'
        );
        $statement->execute([
            'attempt_count' => $code->attemptCount(),
            'used_at' => $code->usedAt() === null ? null : $this->format($code->usedAt()),
            'invalidated_at' => $code->invalidatedAt() === null ? null : $this->format($code->invalidatedAt()),
            'admin_id' => $adminId,
            'code_hash' => $code->codeHash(),
        ]);
    }

    public function replaceActiveIfAllowed(
        int $adminId,
        TwoFactorCode $code,
        DateTimeImmutable $sentAt,
        DateTimeImmutable $latestAllowedPreviousSend,
    ): bool
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lock = $this->pdo->prepare('SELECT id FROM admins WHERE id = :admin_id FOR UPDATE');
            $lock->execute(['admin_id' => $adminId]);

            $latest = $this->pdo->prepare(
                'SELECT sent_at FROM admin_login_codes
                 WHERE admin_id = :admin_id ORDER BY sent_at DESC, id DESC LIMIT 1'
            );
            $latest->execute(['admin_id' => $adminId]);
            $lastSentAt = $latest->fetchColumn();
            if ($lastSentAt !== false && (string) $lastSentAt > $this->format($latestAllowedPreviousSend)) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return false;
            }

            $invalidate = $this->pdo->prepare(
                'UPDATE admin_login_codes
                 SET invalidated_at = :invalidated_at
                 WHERE admin_id = :admin_id AND used_at IS NULL AND invalidated_at IS NULL'
            );
            $invalidate->execute([
                'invalidated_at' => $this->format($sentAt),
                'admin_id' => $adminId,
            ]);

            $insert = $this->pdo->prepare(
                'INSERT INTO admin_login_codes
                    (admin_id, code_hash, expires_at, attempt_count, sent_at, used_at, invalidated_at)
                 VALUES
                    (:admin_id, :code_hash, :expires_at, :attempt_count, :sent_at, :used_at, :invalidated_at)'
            );
            $insert->execute([
                'admin_id' => $adminId,
                'code_hash' => $code->codeHash(),
                'expires_at' => $this->format($code->expiresAt()),
                'attempt_count' => $code->attemptCount(),
                'sent_at' => $this->format($sentAt),
                'used_at' => $code->usedAt() === null ? null : $this->format($code->usedAt()),
                'invalidated_at' => $code->invalidatedAt() === null ? null : $this->format($code->invalidatedAt()),
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('Europe/Budapest'))->format(self::DATE_FORMAT);
    }
}
