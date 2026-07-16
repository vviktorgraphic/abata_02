<?php

declare(strict_types=1);

namespace Tests\Integration\Authentication;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditMetadata;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Auth\PdoRateLimitRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoSecurityPersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testRateLimitRepositoryCountsLocksAndClearsOnlyHashedBucket(): void
    {
        $repository = new PdoRateLimitRepository($this->pdo);
        $hash = hash('sha256', 'already-pseudonymized-key');
        $now = $this->date('2026-07-16 12:00:00');
        $repository->recordFailure('admin_login_ip', $hash, $now);
        $repository->recordFailure('admin_login_ip', $hash, $now->add(new DateInterval('PT1S')));
        self::assertSame(2, $repository->countFailures('admin_login_ip', $hash, $now));

        $until = $now->add(new DateInterval('PT15M'));
        $repository->lock('admin_login_ip', $hash, $until);
        self::assertSame($until->format('Y-m-d H:i:s'), $repository->lockedUntil('admin_login_ip', $hash)?->format('Y-m-d H:i:s'));
        self::assertSame(2, $repository->countFailures('admin_login_ip', $hash, $now));

        $repository->clearFailures('admin_login_ip', $hash);
        self::assertSame(0, $repository->countFailures('admin_login_ip', $hash, $now));
        self::assertNull($repository->lockedUntil('admin_login_ip', $hash));
    }

    public function testAuditLogPersistsSanitizedEventWithoutRawSecrets(): void
    {
        $ipHash = hash('sha256', '192.0.2.1');
        (new PdoAuditLog($this->pdo))->append(new AuditEvent(
            'admin.login.failed',
            'failure',
            $this->date('2026-07-16 12:00:00'),
            new AuditMetadata(['reason_code' => 'invalid_credentials', 'auth_stage' => 'password']),
            ipHash: $ipHash,
            userAgentSummary: 'Integration browser',
        ));

        $row = $this->pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
        self::assertIsArray($row);
        self::assertSame('admin.login.failed', $row['event_type']);
        self::assertSame($ipHash, $row['ip_hash']);
        $serialized = json_encode($row, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('192.0.2.1', $serialized);
        self::assertStringNotContainsString('password=', $serialized);
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Europe/Budapest'));
    }
}
