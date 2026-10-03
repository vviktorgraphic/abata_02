<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Application\Authentication\AdminUserRepository;
use App\Domain\Authentication\AdminCredential;
use PDO;

final readonly class PdoAdminCredentialRepository implements AdminUserRepository
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

    /** @return list<array{id:int,name:string,email:string,is_active:bool,created_at:string}> */
    public function allForManagement(): array
    {
        $rows = $this->pdo->query('SELECT id, name, email, is_active, created_at FROM admins ORDER BY name, id')->fetchAll();
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email'],
            'is_active' => (bool) $row['is_active'], 'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    public function createAdmin(string $name, string $email, string $passwordHash): int
    {
        $statement = $this->pdo->prepare('INSERT INTO admins (email, password_hash, name, is_active) VALUES (:email, :password_hash, :name, TRUE)');
        $statement->execute(['email' => $email, 'password_hash' => $passwordHash, 'name' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setActive(int $adminId, bool $active): bool
    {
        $statement = $this->pdo->prepare('UPDATE admins SET is_active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $adminId]);
        return $statement->rowCount() === 1;
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admins WHERE is_active = 1')->fetchColumn();
    }
}
