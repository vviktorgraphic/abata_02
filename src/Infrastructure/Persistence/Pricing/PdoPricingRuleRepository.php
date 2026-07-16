<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Pricing;

use App\Application\Pricing\PricingRuleRepository;
use PDO;

final class PdoPricingRuleRepository implements PricingRuleRepository
{
    private const FIELDS = [
        'name', 'rule_type', 'valid_from', 'valid_until', 'nightly_price', 'amount', 'adjustment_mode',
        'base_unit', 'currency', 'minimum_nights', 'maximum_nights', 'applicable_weekdays', 'exemption_key', 'priority',
        'is_active',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $values, int $adminId): int
    {
        $values = $this->whitelist($values);
        $values['created_by_admin_id'] = $adminId;
        $values['updated_by_admin_id'] = $adminId;
        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO pricing_rules (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders),
        ));
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $values, int $adminId): bool
    {
        $values = $this->whitelist($values);
        if ($values === []) {
            return false;
        }
        $values['updated_by_admin_id'] = $adminId;
        $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($values));
        $values['id'] = $id;
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE pricing_rules SET %s WHERE id = :id AND deleted_at IS NULL',
            implode(', ', $assignments),
        ));
        $statement->execute($values);

        return $statement->rowCount() === 1;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM pricing_rules WHERE id = :id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function listAll(bool $includeInactive = true): array
    {
        $sql = 'SELECT * FROM pricing_rules WHERE deleted_at IS NULL';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY rule_type ASC, priority DESC, id ASC';
        $rows = $this->pdo->query($sql)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function setActive(int $id, bool $active, int $adminId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE pricing_rules
             SET is_active = :active, updated_by_admin_id = :admin_id
             WHERE id = :id AND deleted_at IS NULL'
        );
        $statement->execute(['active' => $active ? 1 : 0, 'admin_id' => $adminId, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function findApplicable(string $type, string $arrivalDate, string $departureDate, int $nights): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM pricing_rules
             WHERE deleted_at IS NULL AND is_active = 1 AND rule_type = :type
               AND valid_from < :departure AND valid_until > :arrival
               AND minimum_nights <= :nights
               AND (maximum_nights IS NULL OR maximum_nights >= :nights_max)
             ORDER BY priority DESC, id ASC'
        );
        $statement->execute([
            'type' => $type,
            'arrival' => $arrivalDate,
            'departure' => $departureDate,
            'nights' => $nights,
            'nights_max' => $nights,
        ]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function hasEqualPriorityConflict(
        string $type,
        string $validFrom,
        string $validUntil,
        int $priority,
        ?int $exceptId = null,
    ): bool {
        $sql = 'SELECT 1 FROM pricing_rules
                WHERE deleted_at IS NULL AND is_active = 1 AND rule_type = :type AND priority = :priority
                  AND valid_from < :valid_until AND valid_until > :valid_from';
        $parameters = [
            'type' => $type,
            'priority' => $priority,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function whitelist(array $values): array
    {
        return array_intersect_key($values, array_flip(self::FIELDS));
    }
}
