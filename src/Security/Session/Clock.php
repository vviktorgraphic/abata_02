<?php

declare(strict_types=1);

namespace App\Security\Session;

interface Clock
{
    /** Returns the current Unix timestamp. */
    public function now(): int;
}
