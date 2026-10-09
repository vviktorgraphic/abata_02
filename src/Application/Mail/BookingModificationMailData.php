<?php
declare(strict_types=1);
namespace App\Application\Mail;

final readonly class BookingModificationMailData
{
    /** @param list<int> $childAges */
    public function __construct(
        public string $recipient,
        public string $contactName,
        public string $reference,
        public string $arrivalDate,
        public string $departureDate,
        public int $nights,
        public int $adults,
        public array $childAges,
        public string $accommodationFee,
        public string $tourismTax,
        public string $total,
        public ?string $unchangedDepositAmount,
        public string $currency,
    ) {
        if ($currency !== 'HUF') throw new \InvalidArgumentException('A módosítási levél kizárólag HUF pénznemet támogat.');
    }
}
