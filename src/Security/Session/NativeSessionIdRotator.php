<?php

declare(strict_types=1);

namespace App\Security\Session;

use RuntimeException;

final class NativeSessionIdRotator implements SessionIdRotator
{
    public function rotate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new RuntimeException('The session identifier could not be regenerated.');
        }
    }
}
