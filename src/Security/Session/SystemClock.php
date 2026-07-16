<?php

declare(strict_types=1);

namespace App\Security\Session;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
