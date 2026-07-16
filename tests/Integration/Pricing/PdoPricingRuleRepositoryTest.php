<?php

declare(strict_types=1);

namespace Tests\Integration\Pricing;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Pricing\PdoPricingRuleRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoPricingRuleRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    /** @var list<int> */
    private array $ruleIds = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $statement = $this->pdo->prepare(
            'INSERT INTO admins (email, password_hash, name) VALUES (:email, :hash, :name)'
        );
        $statement->execute([
            'email' => 'pricing-persistence-' . bin2hex(random_bytes(5)) . '@example.invalid',
            'hash' => password_hash('irrelevant-test-password', PASSWORD_DEFAULT),
            'name' => 'Pricing Persistence Test',
        ]);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->ruleIds as $id) {
            $this->pdo->prepare('DELETE FROM pricing_rules WHERE id = :id')->execute(['id' => $id]);
        }
        $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $this->adminId]);
    }

    public function testCrudActivationAndAdminAttribution(): void
    {
        $repository = new PdoPricingRuleRepository($this->pdo);
        $id = $repository->create($this->values('Base price', 10), $this->adminId);
        $this->ruleIds[] = $id;

        $created = $repository->find($id);
        self::assertNotNull($created);
        self::assertSame((string) $this->adminId, (string) $created['created_by_admin_id']);
        self::assertSame('per_person_per_night', $created['base_unit']);

        self::assertTrue($repository->update($id, ['name' => 'Updated', 'priority' => 20], $this->adminId));
        self::assertSame('Updated', $repository->find($id)['name']);
        self::assertTrue($repository->setActive($id, false, $this->adminId));
        self::assertSame([], array_values(array_filter(
            $repository->listAll(false),
            static fn (array $row): bool => (int) $row['id'] === $id,
        )));
        self::assertTrue($repository->setActive($id, true, $this->adminId));
        self::assertSame([$id], array_map(
            static fn (array $row): int => (int) $row['id'],
            array_values(array_filter($repository->findApplicable('base', '2040-06-10', '2040-06-13', 3), static fn (array $row): bool => (int) $row['id'] === $id)),
        ));
    }

    public function testOverlappingEqualPriorityConflictIsExplicit(): void
    {
        $repository = new PdoPricingRuleRepository($this->pdo);
        $id = $repository->create($this->values('First', 50), $this->adminId);
        $this->ruleIds[] = $id;

        self::assertTrue($repository->hasEqualPriorityConflict('base', '2040-06-30', '2040-07-02', 50));
        self::assertFalse($repository->hasEqualPriorityConflict('base', '2040-06-30', '2040-07-02', 51));
        self::assertFalse($repository->hasEqualPriorityConflict('base', '2040-06-30', '2040-07-02', 50, $id));
    }

    /** @return array<string, mixed> */
    private function values(string $name, int $priority): array
    {
        return [
            'name' => $name,
            'rule_type' => 'base',
            'valid_from' => '2040-01-01',
            'valid_until' => '2041-01-01',
            'nightly_price' => '10000.00',
            'amount' => '10000.00',
            'adjustment_mode' => 'fixed',
            'base_unit' => 'per_person_per_night',
            'currency' => 'HUF',
            'minimum_nights' => 1,
            'maximum_nights' => null,
            'applicable_weekdays' => '[5,6]',
            'exemption_key' => null,
            'priority' => $priority,
        ];
    }
}
