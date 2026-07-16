<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

interface AdminActionRateLimiter
{
    public function allow(int $adminId, string $action): bool;
}
