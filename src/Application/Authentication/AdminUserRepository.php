<?php

declare(strict_types=1);

namespace App\Application\Authentication;

interface AdminUserRepository extends AdminCredentialRepository
{
    /** @return list<array{id:int,name:string,email:string,is_active:bool,created_at:string}> */
    public function allForManagement(): array;
    public function createAdmin(string $name, string $email, string $passwordHash): int;
    public function setActive(int $adminId, bool $active): bool;
    public function countActive(): int;
}
