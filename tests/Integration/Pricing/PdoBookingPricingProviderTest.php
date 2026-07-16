<?php

declare(strict_types=1);

namespace Tests\Integration\Pricing;

use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Pricing\PricingClock;
use App\Application\Pricing\PricingConfigurationException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Pricing\PdoBookingPricingProvider;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoBookingPricingProviderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->pdo->exec('DELETE FROM pricing_rules');
    }

    public function testSelectsTheHighestPriorityActiveRuleAndCalculatesPersonNights(): void
    {
        $this->insertRule('Fallback', '1000.00', 1, true);
        $winnerId = $this->insertRule('Configured', '2500.00', 20, true);
        $this->insertRule('Inactive', '9999.00', 100, false);

        $pricing = $this->provider()->calculate($this->pdo, $this->command());

        self::assertSame('22500.00', $pricing->totalAmount); // 3 nights x (2 adults + 1 child)
        self::assertSame(3, $pricing->snapshot['nights']);
        self::assertSame([$winnerId], $pricing->snapshot['pricing_rule_ids']);
        self::assertSame('person_night', $pricing->snapshot['base_unit']);
        self::assertSame('HUF', $pricing->snapshot['currency']);
        self::assertSame('22500.00', $pricing->snapshot['total']);
    }

    public function testAdjacentDepartureIsExcludedFromNightsCalculation(): void
    {
        $this->insertRule('One night', '1000.00', 1, true);
        $command = $this->command('2040-06-10', '2040-06-11');

        $pricing = $this->provider()->calculate($this->pdo, $command);

        self::assertSame(1, $pricing->snapshot['nights']);
        self::assertSame('3000.00', $pricing->totalAmount);
    }

    public function testFailsInsteadOfPersistingAZeroPriceWhenNoRuleCoversStay(): void
    {
        $this->expectException(PricingConfigurationException::class);
        $this->provider()->calculate($this->pdo, $this->command());
    }

    public function testEqualWinningPrioritiesAreRejectedAsAmbiguousConfiguration(): void
    {
        $this->insertRule('First', '1000.00', 10, true);
        $this->insertRule('Second', '2000.00', 10, true);

        $this->expectException(PricingConfigurationException::class);
        $this->provider()->calculate($this->pdo, $this->command());
    }

    public function testSnapshotIsDetachedFromLaterRuleChangesAndReadonlyPricingCannotBeReassigned(): void
    {
        $ruleId = $this->insertRule('Frozen', '1000.00', 1, true);
        $pricing = $this->provider()->calculate($this->pdo, $this->command());
        $this->pdo->prepare('UPDATE pricing_rules SET nightly_price = :price WHERE id = :id')
            ->execute(['price' => '9000.00', 'id' => $ruleId]);
        $recalculated = $this->provider()->calculate($this->pdo, $this->command());

        self::assertSame('9000.00', $pricing->totalAmount);
        self::assertSame('9000.00', $pricing->snapshot['total']);
        self::assertSame('81000.00', $recalculated->totalAmount);
        $this->expectException(\Error::class);
        $pricing->snapshot = [];
    }

    private function provider(): PdoBookingPricingProvider
    {
        $clock = new class implements PricingClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2040-01-02T03:04:05+01:00', new DateTimeZone('Europe/Budapest'));
            }
        };

        return new PdoBookingPricingProvider($clock);
    }

    private function command(string $arrival = '2040-06-10', string $departure = '2040-06-13'): BookingPersistenceCommand
    {
        return new BookingPersistenceCommand(
            'pricing-test-key',
            str_repeat('a', 64),
            'PRICE-TEST',
            $arrival,
            $departure,
            'Test Guest',
            'guest@example.test',
            '+3612345678',
            2,
            [6],
            null,
        );
    }

    private function insertRule(string $name, string $price, int $priority, bool $active): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pricing_rules
                (name, valid_from, valid_until, nightly_price, base_unit, currency, minimum_nights, priority, is_active)
             VALUES (:name, :valid_from, :valid_until, :price, :base_unit, :currency, 1, :priority, :active)'
        );
        $statement->execute([
            'name' => $name,
            'valid_from' => '2040-01-01',
            'valid_until' => '2041-01-01',
            'price' => $price,
            'base_unit' => 'person_night',
            'currency' => 'HUF',
            'priority' => $priority,
            'active' => $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
