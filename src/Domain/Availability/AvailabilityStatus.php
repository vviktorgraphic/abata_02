<?php

declare(strict_types=1);

namespace App\Domain\Availability;

enum AvailabilityStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case DepartureOnly = 'departure_only';
    case ArrivalOnly = 'arrival_only';
    case Turnover = 'turnover';
    case Blocked = 'blocked';
    case Past = 'past';
}

