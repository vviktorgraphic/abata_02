<?php
declare(strict_types=1);
namespace App\Presentation;
use App\Domain\Booking\BookingStatus;
final class BookingStatusLabel
{
    public static function for(string $status): string { return BookingStatus::tryFrom($status)?->label() ?? $status; }
}
