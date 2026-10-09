<?php
declare(strict_types=1);
namespace App\Application\Booking;
use App\Domain\Pricing\PricingResult;
final readonly class BookingModificationPreview
{
    public function __construct(
        public int $bookingId,
        public int $version,
        public ConfirmedBookingModification $modification,
        public PricingResult $pricing,
        public ?string $unchangedDepositAmount,
        public string $pricingHash,
    ) {}
}
