<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final readonly class AdminActionGuardResult
{
    /** @param array{id: int, name: string}|null $admin */
    public function __construct(
        public ?array $admin,
        public ?string $adminNote,
        public ?AdminResponse $rejection = null,
    ) {
        if (($admin === null) === ($rejection === null)) {
            throw new \InvalidArgumentException('A guard result must be either allowed or rejected.');
        }
    }

    public function allowed(): bool
    {
        return $this->rejection === null;
    }
}
