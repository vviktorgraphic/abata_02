<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Application\Authentication\AdminCredentialRepository;
use App\Domain\Authentication\AdminCredential;
use PDO;

final readonly class PdoAdminCredentialRepository implements AdminCredentialRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?AdminCredential
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, password_hash, is_active FROM admins WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $normalizedEmail]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new AdminCredential(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (bool) $row['is_active'],
        );
    }

    public function updatePasswordHash(int $adminId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admins SET password_hash = :password_hash WHERE id = :admin_id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'admin_id' => $adminId]);
    }

    /** @return array{id: int, name: string, email: string}|null */
    public function findSummaryById(int $adminId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, email FROM admins WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $adminId]);
        $row = $statement->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email']];
    }
}
