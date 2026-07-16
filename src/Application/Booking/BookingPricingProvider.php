<?php

declare(strict_types=1);

namespace App\Application\Booking;

use PDO;

interface BookingPricingProvider
{
    public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing;
}
