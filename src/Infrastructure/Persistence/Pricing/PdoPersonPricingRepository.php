<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Pricing;

use App\Application\Pricing\PersonPricingRepository;
use App\Application\Pricing\PricingVersionConflict;
use App\Domain\Pricing\ChildPriceBand;
use App\Domain\Pricing\PersonPricingConfiguration;
use PDO;

final readonly class PdoPersonPricingRepository implements PersonPricingRepository
{
    public function __construct(private PDO $pdo) {}

    public function get(): PersonPricingConfiguration
    {
        // One statement gives a coherent configuration even outside a transaction.
        $rows = $this->pdo->query('SELECT c.version, c.pricing_mode, c.adult_weekday_price, c.adult_weekend_price,
            b.id, b.min_age, b.max_age, b.weekday_price, b.weekend_price, b.is_active, b.sort_order
            FROM person_pricing_configuration c LEFT JOIN pricing_child_bands b ON 1 = 1
            WHERE c.id = 1 ORDER BY b.sort_order, b.id')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) { throw new \RuntimeException('Person pricing configuration is missing.'); }
        $bands = [];
        foreach ($rows as $row) {
            if ($row['id'] === null) { continue; }
            $bands[] = new ChildPriceBand((int) $row['min_age'], (int) $row['max_age'],
                (string) $row['weekday_price'], (string) $row['weekend_price'], (bool) $row['is_active'],
                (int) $row['sort_order'], (int) $row['id']);
        }
        return new PersonPricingConfiguration((int) $rows[0]['version'], (string) $rows[0]['pricing_mode'],
            $rows[0]['adult_weekday_price'], $rows[0]['adult_weekend_price'], $bands);
    }

    public function save(PersonPricingConfiguration $configuration, int $expectedVersion, int $adminId): PersonPricingConfiguration
    {
        if ($configuration->mode === 'person' && ($configuration->adultWeekdayPrice === null || $configuration->adultWeekendPrice === null)) {
            throw new \InvalidArgumentException('Both adult prices are required before enabling person pricing.');
        }
        if ($this->pdo->inTransaction()) { throw new \LogicException('Person pricing save owns its transaction.'); }
        $this->pdo->beginTransaction();
        try {
            $version = (int) $this->pdo->query('SELECT version FROM person_pricing_configuration WHERE id = 1 FOR UPDATE')->fetchColumn();
            if ($version !== $expectedVersion || $configuration->version !== $expectedVersion) {
                throw new PricingVersionConflict('Az árképzést másik admin módosította. Frissítse az oldalt.');
            }
            $existing = array_map('intval', $this->pdo->query('SELECT id FROM pricing_child_bands')->fetchAll(PDO::FETCH_COLUMN));
            $this->pdo->exec('DELETE FROM pricing_child_age_coverage');
            $retained = [];
            foreach ($configuration->childBands as $band) {
                $params = ['min'=>$band->minAge, 'max'=>$band->maxAge, 'weekday'=>$band->weekdayPrice,
                    'weekend'=>$band->weekendPrice, 'active'=>$band->active ? 1 : 0, 'sort'=>$band->sortOrder];
                if ($band->id !== 0) {
                    if (!in_array($band->id, $existing, true)) { throw new \InvalidArgumentException('Unknown child price band.'); }
                    $params['id'] = $band->id;
                    $this->pdo->prepare('UPDATE pricing_child_bands SET min_age=:min, max_age=:max,
                        weekday_price=:weekday, weekend_price=:weekend, is_active=:active, sort_order=:sort WHERE id=:id')->execute($params);
                    $id = $band->id;
                } else {
                    $this->pdo->prepare('INSERT INTO pricing_child_bands (min_age,max_age,weekday_price,weekend_price,is_active,sort_order)
                        VALUES (:min,:max,:weekday,:weekend,:active,:sort)')->execute($params);
                    $id = (int) $this->pdo->lastInsertId();
                }
                $retained[] = $id;
                if ($band->active) {
                    $coverage = $this->pdo->prepare('INSERT INTO pricing_child_age_coverage (age,band_id) VALUES (:age,:band)');
                    foreach (range($band->minAge, $band->maxAge) as $age) { $coverage->execute(['age'=>$age, 'band'=>$id]); }
                }
            }
            // Band lifecycle is activation/inactivation; omission cannot silently delete one.
            if (array_diff($existing, $retained) !== []) { throw new \InvalidArgumentException('Existing child price bands must be retained; deactivate them instead.'); }
            $this->pdo->prepare('UPDATE person_pricing_configuration SET version=version+1, pricing_mode=:mode,
                adult_weekday_price=:weekday, adult_weekend_price=:weekend, updated_by_admin_id=:admin, updated_at=:now WHERE id=1')
                ->execute(['mode'=>$configuration->mode,'weekday'=>$configuration->adultWeekdayPrice,
                    'weekend'=>$configuration->adultWeekendPrice,'admin'=>$adminId,
                    'now'=>(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest')))->format('Y-m-d H:i:s')]);
            $result = $this->get();
            (new \App\Infrastructure\Persistence\Auth\PdoAuditLog($this->pdo))->append(new \App\Application\Audit\AuditEvent(
                'person_pricing.updated', 'success',
                new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest')),
                new \App\Application\Audit\AuditMetadata([
                    'target_type' => 'person_pricing', 'target_id' => '1',
                    'previous_version' => $version, 'version' => $result->version, 'mode' => $result->mode,
                ]), $adminId,
            ));
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
}
