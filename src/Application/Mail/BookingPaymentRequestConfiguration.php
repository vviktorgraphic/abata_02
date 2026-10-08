<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingPaymentRequestConfiguration
{
    public function __construct(
        public string $beneficiary,
        public string $bankAccount,
        public int $advancePercent = 50,
        public string $bankName = '',
        public string $swiftBic = '',
    ) {
    }

    public function assertConfigured(): void
    {
        $this->assertPercentage();
        foreach ([$this->beneficiary, $this->bankAccount, $this->bankName, $this->swiftBic] as $value) {
            if (trim($value) === '' || preg_match('/[<>\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException('A díjbekérő banki konfigurációja hiányos (PAYMENT_REQUEST_BENEFICIARY, PAYMENT_REQUEST_BANK_ACCOUNT, PAYMENT_REQUEST_BANK_NAME, PAYMENT_REQUEST_SWIFT_BIC).');
            }
        }
    }

    public function advanceFor(string $accommodationFee): string
    {
        $this->assertPercentage();
        if (preg_match('/\A(\d{1,10})(?:\.(\d{2}))?\z/', $accommodationFee, $parts) !== 1) {
            throw new \InvalidArgumentException('A tárolt foglalási összeg érvénytelen.');
        }
        // Decimal HALF_UP to whole HUF, equivalent to PHP_ROUND_HALF_UP without binary floats.
        $minor = (int) $parts[1] * 100 + (int) ($parts[2] ?? '0');
        return intdiv($minor * $this->advancePercent + 5000, 10000) . '.00';
    }

    private function assertPercentage(): void
    {
        if ($this->advancePercent < 1 || $this->advancePercent > 100) {
            throw new \InvalidArgumentException('A PAYMENT_REQUEST_ADVANCE_PERCENT értéke 1 és 100 közötti egész szám lehet.');
        }
    }
}
