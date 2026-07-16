<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Pricing;

use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPricing;
use App\Application\Booking\BookingPricingProvider;
use App\Application\Pricing\BudapestPricingClock;
use App\Application\Pricing\PricingClock;
use App\Application\Pricing\PricingConfigurationException;
use PDO;

final class PdoBookingPricingProvider implements BookingPricingProvider
{
    public function __construct(private readonly PricingClock $clock = new BudapestPricingClock())
    {
    }

    public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
    {
        $arrival = $this->date($command->arrivalDate);
        $departure = $this->date($command->departureDate);
        $nights = (int) $arrival->diff($departure)->format('%r%a');
        if ($nights < 1) {
            throw new \InvalidArgumentException('Departure must be later than arrival.');
        }

        $statement = $pdo->prepare(
            'SELECT id, name, nightly_price, base_unit, currency, priority
             FROM pricing_rules
             WHERE is_active = 1
               AND valid_from <= :arrival
               AND valid_until >= :departure
               AND minimum_nights <= :nights
             ORDER BY priority DESC, id ASC'
        );
        $statement->execute([
            'arrival' => $command->arrivalDate,
            'departure' => $command->departureDate,
            'nights' => $nights,
        ]);
        /** @var list<array<string, mixed>> $rules */
        $rules = $statement->fetchAll();
        if ($rules === []) {
            throw new PricingConfigurationException('No active pricing rule covers the requested stay.');
        }
        if (isset($rules[1]) && (int) $rules[0]['priority'] === (int) $rules[1]['priority']) {
            throw new PricingConfigurationException('Multiple pricing rules have the same winning priority.');
        }

        $rule = $rules[0];
        if ($rule['base_unit'] !== 'person_night' || $rule['currency'] !== 'HUF') {
            throw new PricingConfigurationException('The selected pricing rule uses an unsupported unit or currency.');
        }

        $people = $command->adults + count($command->childAges);
        $unitMinor = $this->minorUnits((string) $rule['nightly_price']);
        $units = $nights * $people;
        if ($people < 1 || ($unitMinor !== 0 && $units > intdiv(PHP_INT_MAX, $unitMinor))) {
            throw new PricingConfigurationException('The calculated price is outside the supported range.');
        }
        $totalMinor = $unitMinor * $units;
        $unitAmount = $this->decimal($unitMinor);
        $totalAmount = $this->decimal($totalMinor);

        $snapshot = [
            'version' => 1,
            'calculated_at' => $this->clock->now()->format(DATE_ATOM),
            'arrival_date' => $command->arrivalDate,
            'departure_date' => $command->departureDate,
            'nights' => $nights,
            'adults' => $command->adults,
            'children' => array_map(static fn (int $age): array => ['age' => $age], $command->childAges),
            'pricing_rule_ids' => [(int) $rule['id']],
            'base_unit' => 'person_night',
            'line_items' => [[
                'type' => 'accommodation',
                'description' => (string) $rule['name'],
                'quantity' => $units,
                'unit_amount' => $unitAmount,
                'total' => $totalAmount,
            ]],
            'subtotal' => $totalAmount,
            'taxes' => '0.00',
            'total' => $totalAmount,
            'currency' => 'HUF',
        ];

        return new BookingPricing($totalAmount, 'HUF', $snapshot);
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Budapest'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Booking dates must use YYYY-MM-DD format.');
        }

        return $date;
    }

    private function minorUnits(string $amount): int
    {
        if (!preg_match('/^(\d+)\.(\d{2})$/', $amount, $matches)) {
            throw new PricingConfigurationException('The selected price is not a valid decimal amount.');
        }

        $minor = ((int) $matches[1] * 100) + (int) $matches[2];
        if ($minor < 0) {
            throw new PricingConfigurationException('The selected price is outside the supported range.');
        }

        return $minor;
    }

    private function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
