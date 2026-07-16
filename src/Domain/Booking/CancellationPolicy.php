<?php

declare(strict_types=1);

namespace App\Domain\Booking;

final readonly class CancellationPolicy
{
    public const RULE_VERSION = 1;
    private const TIMEZONE = 'Europe/Budapest';

    public function calculate(
        string $arrivalDate,
        string $accommodationFee,
        \DateTimeImmutable $cancelledAt,
        string $currency = 'HUF',
    ): CancellationResult {
        if ($currency !== 'HUF' || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $accommodationFee)) {
            throw new \InvalidArgumentException('Cancellation requires a non-negative HUF accommodation fee.');
        }
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $arrival = \DateTimeImmutable::createFromFormat('!Y-m-d', $arrivalDate, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($arrival === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Invalid arrival date.');
        }

        $localCancellation = $cancelledAt->setTimezone($timezone);
        $cancellationDate = $localCancellation->setTime(0, 0);
        $deadline = $arrival->modify('-7 days');
        $daysBeforeArrival = (int) $cancellationDate->diff($arrival)->format('%r%a');
        $rate = $cancellationDate <= $deadline ? 0.0 : 0.5;
        [$whole, $fraction] = array_pad(explode('.', $accommodationFee, 2), 2, '');
        $feeCents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        // 50% of a two-decimal fee, rounded to a whole HUF using HALF_UP.
        $penaltyHuf = $rate === 0.0 ? 0 : intdiv($feeCents + 100, 200);
        $amountFormatted = $penaltyHuf . '.00';
        $feeFormatted = intdiv($feeCents, 100) . '.' . str_pad((string) ($feeCents % 100), 2, '0', STR_PAD_LEFT);

        $snapshot = [
            'version' => self::RULE_VERSION,
            'cancelled_at' => $localCancellation->format(DATE_ATOM),
            'arrival_date' => $arrival->format('Y-m-d'),
            'free_cancellation_deadline' => $deadline->format('Y-m-d'),
            'days_before_arrival' => $daysBeforeArrival,
            'accommodation_fee' => $feeFormatted,
            'penalty_rate' => $rate,
            'penalty_amount' => $amountFormatted,
            'currency' => 'HUF',
        ];

        return new CancellationResult(
            $localCancellation->format('Y-m-d H:i:s'),
            $rate === 0.0 ? '0.0000' : '0.5000',
            $amountFormatted,
            'HUF',
            self::RULE_VERSION,
            $snapshot,
        );
    }
}
