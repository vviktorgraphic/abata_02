<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Pricing\PricingResult;
use App\Domain\Pricing\WholeHuf;

final readonly class AdminPricingOverride
{
    public function applyToResult(PricingResult $calculated, ?AdminCustomPrice $customPrice): PricingResult
    {
        if ($customPrice === null) {
            return $calculated;
        }

        [$accommodation, $tax, $total, $snapshot] = $this->effectiveValues(
            $calculated->accommodationFee,
            $calculated->tourismTax,
            $calculated->totalAmount,
            $calculated->currency,
            $calculated->snapshot,
            $customPrice,
        );

        return new PricingResult(
            $total,
            $accommodation,
            $tax,
            $calculated->currency,
            $calculated->lineItems,
            $calculated->appliedRuleIds,
            $snapshot,
        );
    }

    public function applyToBookingPricing(BookingPricing $calculated, ?AdminCustomPrice $customPrice): BookingPricing
    {
        if ($customPrice === null) {
            return $calculated;
        }

        $accommodation = $this->money($calculated->snapshot['accommodation_fee'] ?? null);
        $tax = $this->money($calculated->snapshot['taxes'] ?? null);
        if ($accommodation === null || $tax === null) {
            throw new \RuntimeException('Az admin ár-felülbíráláshoz teljes kalkulált ár-pillanatkép szükséges.');
        }
        [, , $total, $snapshot] = $this->effectiveValues(
            $accommodation,
            $tax,
            $calculated->totalAmount,
            $calculated->currency,
            $calculated->snapshot,
            $customPrice,
        );

        return new BookingPricing($total, $calculated->currency, $snapshot);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{string,string,string,array<string,mixed>}
     */
    private function effectiveValues(
        string $calculatedAccommodation,
        string $calculatedTax,
        string $calculatedTotal,
        string $currency,
        array $snapshot,
        AdminCustomPrice $customPrice,
    ): array {
        if ($currency !== 'HUF') {
            throw new \InvalidArgumentException('Az egyedi admin ár kizárólag HUF pénznemmel használható.');
        }
        $calculatedAccommodation = WholeHuf::normalize($calculatedAccommodation);
        $calculatedTax = WholeHuf::normalize($calculatedTax);
        $calculatedTotal = WholeHuf::normalize($calculatedTotal);
        $taxForints = (int) substr($calculatedTax, 0, -3);
        if ($customPrice->wholeForints() < $taxForints) {
            throw new AdminCustomPriceInvalid('Az egyedi végösszeg nem lehet alacsonyabb az IFA összegénél.');
        }

        $customAccommodation = ($customPrice->wholeForints() - $taxForints) . '.00';
        $snapshot['accommodation_fee'] = $customAccommodation;
        $snapshot['taxes'] = $calculatedTax;
        $snapshot['total'] = $customPrice->total;
        $snapshot['currency'] = 'HUF';
        $snapshot['price_override'] = [
            'type' => 'admin_custom_total',
            'calculated_accommodation_fee' => $calculatedAccommodation,
            'calculated_tourism_tax' => $calculatedTax,
            'calculated_total' => $calculatedTotal,
            'custom_accommodation_fee' => $customAccommodation,
            'custom_total' => $customPrice->total,
        ];

        return [$customAccommodation, $calculatedTax, $customPrice->total, $snapshot];
    }

    private function money(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        try {
            return WholeHuf::normalize((string) $value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
