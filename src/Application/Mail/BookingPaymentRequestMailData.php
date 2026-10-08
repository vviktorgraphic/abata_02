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
        public ?string $accommodationFee = null,
        public ?string $taxes = null,
        public ?string $paymentReference = null,
        public string $bankName = '',
        public string $swiftBic = '',
        public int $templateVersion = 1,
    ) {
        if ($currency !== 'HUF') {
            throw new \InvalidArgumentException('A díjbekérő kizárólag HUF pénznemet támogat.');
        }
        if ($templateVersion === 2 && ($accommodationFee === null || $taxes === null || $paymentReference === null
            || $paymentReference === '' || $bankName === '' || $swiftBic === '')) {
            throw new \InvalidArgumentException('A v2 díjbekérő snapshot hiányos.');
        }
    }

    /** @return array<string, string|int> */
    public function payload(): array
    {
        $payload = [
            'booking_reference' => $this->reference, 'recipient' => $this->recipient,
            'contact_name' => $this->contactName, 'arrival_date' => $this->arrivalDate,
            'departure_date' => $this->departureDate, 'currency' => $this->currency,
            'total' => $this->total, 'advance_percent' => $this->advancePercent,
            'advance_amount' => $this->advanceAmount, 'beneficiary' => $this->beneficiary,
            'bank_account' => $this->bankAccount, 'template_version' => $this->templateVersion,
        ];
        if ($this->templateVersion === 2) {
            $payload += [
                'accommodation_fee' => (string) $this->accommodationFee,
                'taxes' => (string) $this->taxes,
                'payment_reference' => (string) $this->paymentReference,
                'bank_name' => $this->bankName,
                'swift_bic' => $this->swiftBic,
            ];
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self((string) $payload['booking_reference'], (string) $payload['recipient'], (string) $payload['contact_name'],
            (string) $payload['arrival_date'], (string) $payload['departure_date'], (string) $payload['currency'], (string) $payload['total'],
            (int) $payload['advance_percent'], (string) $payload['advance_amount'], (string) $payload['beneficiary'], (string) $payload['bank_account'],
            isset($payload['accommodation_fee']) ? (string) $payload['accommodation_fee'] : null,
            isset($payload['taxes']) ? (string) $payload['taxes'] : null,
            isset($payload['payment_reference']) ? (string) $payload['payment_reference'] : null,
            (string) ($payload['bank_name'] ?? ''), (string) ($payload['swift_bic'] ?? ''), (int) ($payload['template_version'] ?? 1));
    }

    public function subject(): string
    {
        return $this->templateVersion === 2
            ? 'Foglalási igényét rögzítettük!'
            : 'A Bata – előlegfizetés a foglalás véglegesítéséhez – ' . $this->reference;
    }
}
