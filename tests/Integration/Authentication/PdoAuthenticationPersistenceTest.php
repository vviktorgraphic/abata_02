<?php

declare(strict_types=1);

namespace Tests\Integration\Authentication;

use App\Domain\TwoFactor\TwoFactorCode;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Auth\AdminSessionRepository;
use App\Infrastructure\Persistence\Auth\PdoAdminCredentialRepository;
use App\Infrastructure\Persistence\Auth\PdoTwoFactorCodeStore;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoAuthenticationPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }

        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->pdo->beginTransaction();
        $hash = password_hash('integration-only-password', PASSWORD_DEFAULT);
        self::assertIsString($hash);
        $statement = $this->pdo->prepare(
            'INSERT INTO admins (email, password_hash, name, is_active)
             VALUES (:email, :password_hash, :name, :is_active)'
        );
        $statement->execute([
            'email' => sprintf('persistence-%s@example.invalid', bin2hex(random_bytes(6))),
            'password_hash' => $hash,
            'name' => 'Integration Admin',
            'is_active' => 1,
        ]);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testCredentialLookupAndHashUpdateUseTheAdminRecord(): void
    {
        $repository = new PdoAdminCredentialRepository($this->pdo);
        $select = $this->pdo->prepare('SELECT email, password_hash FROM admins WHERE id = :admin_id');
        $select->execute(['admin_id' => $this->adminId]);
        $email = (string) $select->fetchColumn();

        $credential = $repository->findByNormalizedEmail($email);
        self::assertNotNull($credential);
        self::assertSame($this->adminId, $credential->id);
        self::assertTrue($credential->isActive);
        self::assertNull($repository->findByNormalizedEmail('missing@example.invalid'));

        $replacement = password_hash('replacement-integration-password', PASSWORD_DEFAULT);
        self::assertIsString($replacement);
        $repository->updatePasswordHash($this->adminId, $replacement);
        $select->execute(['admin_id' => $this->adminId]);
        $row = $select->fetch();
        self::assertIsArray($row);
        self::assertSame($replacement, $row['password_hash']);
    }

    public function testReplacingTwoFactorCodeInvalidatesPreviousAndNeverStoresPlainCode(): void
    {
        $store = new PdoTwoFactorCodeStore($this->pdo);
        $sentAt = $this->date('2026-07-16 12:00:00');
        $firstPlain = '123456';
        $secondPlain = '654321';
        $firstHash = password_hash($firstPlain, PASSWORD_DEFAULT);
        $secondHash = password_hash($secondPlain, PASSWORD_DEFAULT);
        self::assertIsString($firstHash);
        self::assertIsString($secondHash);

        self::assertTrue($store->replaceActiveIfAllowed(
            $this->adminId,
            new TwoFactorCode($firstHash, $sentAt->add(new DateInterval('PT10M'))),
            $sentAt,
            $sentAt->sub(new DateInterval('PT1M')),
        ));
        self::assertTrue($store->replaceActiveIfAllowed(
            $this->adminId,
            new TwoFactorCode($secondHash, $sentAt->add(new DateInterval('PT11M'))),
            $sentAt->add(new DateInterval('PT1M')),
            $sentAt,
        ));

        $statement = $this->pdo->prepare(
            'SELECT code_hash, invalidated_at FROM admin_login_codes WHERE admin_id = :admin_id ORDER BY id'
        );
        $statement->execute(['admin_id' => $this->adminId]);
        $rows = $statement->fetchAll();

        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]['invalidated_at']);
        self::assertNull($rows[1]['invalidated_at']);
        self::assertNotSame($firstPlain, $rows[0]['code_hash']);
        self::assertNotSame($secondPlain, $rows[1]['code_hash']);
        self::assertTrue(password_verify($secondPlain, $rows[1]['code_hash']));
        self::assertFalse($store->replaceActiveIfAllowed(
            $this->adminId,
            new TwoFactorCode($firstHash, $sentAt->add(new DateInterval('PT12M'))),
            $sentAt->add(new DateInterval('PT90S')),
            $sentAt->add(new DateInterval('PT30S')),
        ));
    }

    public function testSessionRepositoryStoresOnlyTokenHashAndCanRevokeIt(): void
    {
        $repository = new AdminSessionRepository($this->pdo);
        $rawToken = 'integration-session-secret-' . bin2hex(random_bytes(16));
        $createdAt = $this->date('2026-07-16 12:00:00');
        $repository->create(
            $this->adminId,
            $rawToken,
            'authenticated',
            $createdAt,
            $createdAt->add(new DateInterval('PT15M')),
        );

        $statement = $this->pdo->prepare(
            'SELECT session_token_hash, revoked_at FROM admin_sessions WHERE admin_id = :admin_id'
        );
        $statement->execute(['admin_id' => $this->adminId]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $rawToken), $row['session_token_hash']);
        self::assertNotSame($rawToken, $row['session_token_hash']);
        self::assertNull($row['revoked_at']);

        self::assertSame($this->adminId, $repository->activeAdminId($rawToken, 'authenticated', $createdAt));
        self::assertTrue($repository->touch(
            $rawToken,
            $createdAt->add(new DateInterval('PT5M')),
            $createdAt->add(new DateInterval('PT20M')),
        ));

        self::assertTrue($repository->revoke($rawToken, $createdAt->add(new DateInterval('PT1H'))));
        self::assertFalse($repository->revoke($rawToken, $createdAt->add(new DateInterval('PT2H'))));
    }

    private function date(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime, new DateTimeZone('Europe/Budapest'));
    }
}
