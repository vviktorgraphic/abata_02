<?php

declare(strict_types=1);

namespace App\Domain\Authentication;

final readonly class AdminCredential
{
    public function __construct(
        public int $id,
        public string $email,
        public string $passwordHash,
        public bool $isActive,
    ) {
        if ($this->id < 1 || $this->email === '' || $this->passwordHash === '') {
            throw new \InvalidArgumentException('Invalid admin credential.');
        }
    }
}
