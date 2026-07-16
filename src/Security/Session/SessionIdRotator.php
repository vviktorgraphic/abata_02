<?php

declare(strict_types=1);

namespace App\Security\Session;

interface SessionIdRotator
{
    /** Replaces the current session identifier and deletes the old session. */
    public function rotate(): void;
}
