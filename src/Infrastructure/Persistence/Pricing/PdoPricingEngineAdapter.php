<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Pricing;

use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPricing;
use App\Application\Booking\BookingPricingProvider;
use App\Application\Pricing\PricingConfigurationException;
use App\Application\Pricing\PricingPreviewer;
use App\Domain\Pricing\PricingConfigurationError;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingResult;
use App\Domain\Pricing\PricingRule;
use JsonException;
use PDO;

/**
 * The single infrastructure boundary between persisted pricing rules and the
 * shared domain engine used by public booking creation and admin preview.
 */
final readonly class PdoPricingEngineAdapter implements BookingPricingProvider, PricingPreviewer
{
    public function __construct(private PDO $pdo, private PricingEngine $engine = new PricingEngine())
    {
    }

    public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
    {
        $result = $this->calculateResult($pdo, new PricingInput(
            $command->arrivalDate,
            $command->departureDate,
            $command->adults,
            $command->childAges,
        ));

        return new BookingPricing($result->totalAmount, $result->currency, $result->snapshot);
    }

    public function preview(PricingInput $input): PricingResult
    {
        return $this->calculateResult($this->pdo, $input);
    }

    private function calculateResult(PDO $pdo, PricingInput $input): PricingResult
    {
        try {
            $rows = (new PdoPricingRuleRepository($pdo))->listAll(false);

            return $this->engine->calculate($input, array_map($this->mapRule(...), $rows));
        } catch (PricingConfigurationError|JsonException|\InvalidArgumentException $error) {
            throw new PricingConfigurationException('The persisted pricing configuration is invalid.', 0, $error);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapRule(array $row): PricingRule
    {
        $weekdays = [];
        if ($row['applicable_weekdays'] !== null && $row['applicable_weekdays'] !== '') {
            $decoded = json_decode((string) $row['applicable_weekdays'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded) === false) {
                throw new PricingConfigurationError('Applicable weekdays must be a JSON list.');
            }
            $weekdays = array_map(static fn (mixed $value): int => (int) $value, $decoded);
        }

        $amount = $row['amount'] ?? $row['nightly_price'] ?? null;
        if (!is_numeric($amount)) {
            throw new PricingConfigurationError('Pricing rule amount is missing.');
        }

        return new PricingRule(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['rule_type'],
            (bool) $row['is_active'],
            (string) $row['valid_from'],
            $row['valid_until'] !== null ? (string) $row['valid_until'] : null,
            (int) $row['priority'],
            number_format((float) $amount, 2, '.', ''),
            $row['base_unit'] !== null ? (string) $row['base_unit'] : null,
            $row['adjustment_mode'] !== null ? (string) $row['adjustment_mode'] : null,
            $row['minimum_nights'] !== null ? (int) $row['minimum_nights'] : null,
            $row['maximum_nights'] !== null ? (int) $row['maximum_nights'] : null,
            $row['exemption_key'] !== null ? (string) $row['exemption_key'] : null,
            $weekdays,
        );
    }
}
