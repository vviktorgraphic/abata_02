<?php

declare(strict_types=1);

namespace App\Http;

interface BookingRequestRateLimiter
{
    public function allow(string $clientAddress): bool;
}
