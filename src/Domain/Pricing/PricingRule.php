<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PricingRule
{
    public const TYPES = ['stay_length', 'base', 'seasonal', 'weekend', 'fixed_fee', 'tourism_tax', 'exemption'];
    public const BASE_UNITS = ['per_person_per_night', 'per_night', 'per_booking'];
    public const ADJUSTMENT_MODES = ['fixed', 'percent'];

    /** @param list<int> $applicableWeekdays ISO-8601 weekdays (1=Monday ... 7=Sunday). */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public bool $active,
        public string $validFrom,
        public ?string $validUntil,
        public int $priority,
        public string $amount,
        public ?string $baseUnit = null,
        public ?string $adjustmentMode = null,
        public ?int $minimumNights = null,
        public ?int $maximumNights = null,
        public ?string $exemptionKey = null,
        public array $applicableWeekdays = [],
    ) {
        if ($id < 1 || trim($name) === '' || !in_array($type, self::TYPES, true)) {
            throw new PricingConfigurationError('Pricing rule identity or type is invalid.');
        }
        if (!preg_match('/^\d{1,10}\.\d{2}$/', $amount)) {
            throw new PricingConfigurationError('Pricing amount must be a non-negative decimal with two fraction digits.');
        }
        if ($baseUnit !== null && !in_array($baseUnit, self::BASE_UNITS, true)) {
            throw new PricingConfigurationError('Pricing base unit is invalid.');
        }
        if ($adjustmentMode !== null && !in_array($adjustmentMode, self::ADJUSTMENT_MODES, true)) {
            throw new PricingConfigurationError('Pricing adjustment mode is invalid.');
        }
        if ($minimumNights !== null && $minimumNights < 1 || $maximumNights !== null && $maximumNights < 1
            || $minimumNights !== null && $maximumNights !== null && $minimumNights > $maximumNights) {
            throw new PricingConfigurationError('Pricing stay-length bounds are invalid.');
        }
        foreach ($applicableWeekdays as $weekday) {
            if (!is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new PricingConfigurationError('Weekend weekdays must use ISO values 1 through 7.');
            }
        }
    }
}
