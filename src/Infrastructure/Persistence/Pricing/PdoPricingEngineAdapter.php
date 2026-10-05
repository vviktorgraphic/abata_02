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
use App\Domain\Pricing\OccupancyPricingConfiguration;
use App\Domain\Pricing\OccupancyStayLengthBand;
use App\Domain\Pricing\OccupancyDateOverride;
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
            // The occupancy tables are additive to the legacy pricing schema. During
            // rolling upgrades an older database therefore continues using its
            // immutable legacy calculation until migration 024 is installed.
            $occupancy = $this->occupancyConfiguration($pdo);
            if ($occupancy !== null) {
                $legacyRows = (new PdoPricingRuleRepository($pdo))->listAll(false);
                return $this->engine->calculateOccupancy($input, $occupancy, array_map($this->mapRule(...), $legacyRows));
            }
            $rows = (new PdoPricingRuleRepository($pdo))->listAll(false);

            return $this->engine->calculate($input, array_map($this->mapRule(...), $rows), null, (new PdoPersonPricingRepository($pdo))->get());
        } catch (\App\Domain\Pricing\MissingChildPriceBand $error) {
            throw new \App\Application\Pricing\MissingChildPriceBandException('A megadott gyermekéletkorhoz nincs aktív ársáv.', 0, $error);
        } catch (\App\Domain\Pricing\PersonPricingNotConfigured $error) {
            throw new \App\Application\Pricing\PersonPricingNotConfiguredException('A személyalapú árak még nincsenek beállítva.', 0, $error);
        } catch (PricingConfigurationError|JsonException|\InvalidArgumentException $error) {
            throw new PricingConfigurationException('The persisted pricing configuration is invalid.', 0, $error);
        }
    }

    private function occupancyConfiguration(PDO $pdo): ?OccupancyPricingConfiguration
    {
        try {
            $configuration = $pdo->query('SELECT version, one_night_surcharge FROM occupancy_pricing_configuration WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            if ($configuration === false) return null;
            $bands = [];
            $statement = $pdo->query('SELECT id, guest_count, min_nights, max_nights, nightly_price, is_active, sort_order FROM occupancy_stay_length_bands ORDER BY guest_count, sort_order, id');
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $bands[] = new OccupancyStayLengthBand((int)$row['id'], (int)$row['guest_count'], (int)$row['min_nights'], $row['max_nights'] === null ? null : (int)$row['max_nights'], (string)$row['nightly_price'], (bool)$row['is_active'], (int)$row['sort_order']);
            $overrides = [];
            $statement = $pdo->query('SELECT id, start_date, end_date, price_1_guest, price_2_guests, price_3_guests, price_4_guests, is_active FROM occupancy_date_overrides ORDER BY start_date, id');
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $overrides[] = new OccupancyDateOverride((int)$row['id'], (string)$row['start_date'], (string)$row['end_date'], [1=>(string)$row['price_1_guest'],2=>(string)$row['price_2_guests'],3=>(string)$row['price_3_guests'],4=>(string)$row['price_4_guests']], (bool)$row['is_active']);
            return new OccupancyPricingConfiguration((int)$configuration['version'], (string)$configuration['one_night_surcharge'], $bands, $overrides);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'doesn\'t exist') !== false || stripos($e->getMessage(), 'unknown table') !== false) return null;
            throw $e;
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
            (string) $amount,
            $row['base_unit'] !== null ? (string) $row['base_unit'] : null,
            $row['adjustment_mode'] !== null ? (string) $row['adjustment_mode'] : null,
            $row['minimum_nights'] !== null ? (int) $row['minimum_nights'] : null,
            $row['maximum_nights'] !== null ? (int) $row['maximum_nights'] : null,
            $row['exemption_key'] !== null ? (string) $row['exemption_key'] : null,
            $weekdays,
        );
    }
}
