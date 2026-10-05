<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingPaymentRequestMailData
{
    public function __construct(
        public string $reference,
        public string $recipient,
        public string $contactName,
        public string $arrivalDate,
        public string $departureDate,
        public string $currency,
        public string $total,
        public int $advancePercent,
        public string $advanceAmount,
        public string $beneficiary,
        public string $bankAccount,
    ) {
        if ($currency !== 'HUF') {
            throw new \InvalidArgumentException('A díjbekérő kizárólag HUF pénznemet támogat.');
        }
    }

    /** @return array<string, string|int> */
    public function payload(): array
    {
        return [
            'booking_reference' => $this->reference, 'recipient' => $this->recipient,
            'contact_name' => $this->contactName, 'arrival_date' => $this->arrivalDate,
            'departure_date' => $this->departureDate, 'currency' => $this->currency,
            'total' => $this->total, 'advance_percent' => $this->advancePercent,
            'advance_amount' => $this->advanceAmount, 'beneficiary' => $this->beneficiary,
            'bank_account' => $this->bankAccount, 'template_version' => 1,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['booking_reference'], $payload['recipient'], $payload['contact_name'],
            $payload['arrival_date'], $payload['departure_date'], $payload['currency'], $payload['total'],
            $payload['advance_percent'], $payload['advance_amount'], $payload['beneficiary'], $payload['bank_account']);
    }

    public function subject(): string
    {
        return 'A Bata – előlegfizetés a foglalás véglegesítéséhez – ' . $this->reference;
    }
}
