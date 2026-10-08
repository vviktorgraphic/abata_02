<?php

declare(strict_types=1);

namespace Tests\Integration\Authentication;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadataSanitizer;
use App\Application\Authentication\AuthenticationService;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\TwoFactorMailRenderer;
use App\Application\TwoFactor\IssueTwoFactorCode;
use App\Application\TwoFactor\VerifyTwoFactorCode;
use App\Domain\Authentication\EmailNormalizer;
use App\Domain\Authentication\NativePasswordVerifier;
use App\Domain\TwoFactor\TwoFactorClock;
use App\Domain\TwoFactor\TwoFactorCodeGenerator;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\DefaultAdminAuthWorkflow;
use App\Http\Controller\Admin\HtmlResponse;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Auth\AdminSessionRepository;
use App\Infrastructure\Persistence\Auth\PdoAdminCredentialRepository;
use App\Infrastructure\Persistence\Auth\PdoTwoFactorCodeStore;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\RateLimit\AuthenticationRateLimitPolicies;
use App\Security\RateLimit\RateLimitClock;
use App\Security\RateLimit\RateLimiter;
use App\Security\RateLimit\RateLimitPolicy;
use App\Security\RateLimit\RateLimitRepository;
use App\Security\Session\AdminSession;
use App\Security\Session\NativeSessionIdRotator;
use App\Security\Session\NativeSessionStorage;
use App\Security\Session\SessionCookieOptions;
use App\Security\Session\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AdminAuthenticationWorkflowTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoginTwoFactorRotationAndDashboardUseTheSameAuthenticatedSession(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }

        $pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $email = sprintf('auth-flow-%s@example.invalid', bin2hex(random_bytes(8)));
        $password = 'integration-auth-password';
        $adminId = $this->insertAdmin($pdo, $email, $password);
        $storage = new NativeSessionStorage(new SessionCookieOptions(false, true, 'Lax'));
        $mailer = new InMemoryMailer();
        $clock = new WorkflowClock();
        $sessions = new AdminSessionRepository($pdo, 3600);
        $workflow = new DefaultAdminAuthWorkflow(
            new AuthenticationService(
                new PdoAdminCredentialRepository($pdo),
                new NativePasswordVerifier(),
                new EmailNormalizer(),
                (string) password_hash('dummy-integration-account', PASSWORD_DEFAULT),
            ),
            new PdoAdminCredentialRepository($pdo),
            new IssueTwoFactorCode(new PdoTwoFactorCodeStore($pdo), new TwoFactorCodeGenerator($clock), $clock),
            new VerifyTwoFactorCode(new PdoTwoFactorCodeStore($pdo), $clock),
            $mailer,
            new TwoFactorMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'no-reply@example.invalid', 'A Bata'),
            new AdminSession($storage, new NativeSessionIdRotator(), new SystemClock(), 1800),
            $sessions,
            new RateLimiter(new WorkflowRateLimitRepository(), $clock, 'integration-rate-limit-pepper'),
            new AuthenticationRateLimitPolicies(
                new RateLimitPolicy('login_ip', 10, 900, 900),
                new RateLimitPolicy('login_account', 5, 900, 900),
                new RateLimitPolicy('two_factor_verify', 5, 600, 900),
                new RateLimitPolicy('two_factor_resend', 1, 60, 60),
            ),
            new NullAuditLog(),
            new AuditMetadataSanitizer(),
            $pdo,
            'integration-audit-pepper',
            1800,
        );

        session_id('test-' . bin2hex(random_bytes(16)));
        try {
            self::assertTrue($workflow->login($email, $password, ['ip' => '192.0.2.10']));
            $pendingIdHash = hash('sha256', session_id());
            $pending = $this->sessionRow($pdo, $pendingIdHash);
            self::assertSame('two_factor_pending', $pending['auth_level']);
            self::assertNull($pending['revoked_at']);

            $message = $mailer->lastMessage();
            self::assertNotNull($message);
            $codeMatch = [];
            self::assertSame(1, preg_match('/\b([0-9]{6})\b/', $message->textBody, $codeMatch));
            $code = $codeMatch[1];

            self::assertTrue($workflow->verify($code, ['ip' => '192.0.2.10']));
            $authenticatedIdHash = hash('sha256', session_id());
            self::assertNotSame($pendingIdHash, $authenticatedIdHash);
            $authenticated = $this->sessionRow($pdo, $authenticatedIdHash);
            self::assertSame('authenticated', $authenticated['auth_level']);
            self::assertSame((string) $adminId, (string) $authenticated['admin_id']);
            self::assertSame($pending['created_at'], $authenticated['created_at']);
            self::assertNotNull($this->sessionRow($pdo, $pendingIdHash)['revoked_at']);

            $dashboard = (new DashboardController(
                $workflow,
                new AdminView(dirname(__DIR__, 3) . '/templates'),
                new CsrfTokenManager($storage),
            ))->show();
            self::assertInstanceOf(HtmlResponse::class, $dashboard);
            self::assertSame(200, $dashboard->status);
            self::assertNotNull($workflow->currentAdmin(), 'A refresh must keep the rotated authenticated session active.');
            self::assertFalse($workflow->verify($code), 'A used code without a pending session must not authenticate again.');
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            $delete = $pdo->prepare('DELETE FROM admins WHERE id = :id');
            $delete->execute(['id' => $adminId]);
        }
    }

    private function insertAdmin(PDO $pdo, string $email, string $password): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO admins (email, password_hash, name, is_active) VALUES (:email, :password_hash, :name, TRUE)'
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name' => 'Integration Auth Admin',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function sessionRow(PDO $pdo, string $tokenHash): array
    {
        $statement = $pdo->prepare(
            'SELECT admin_id, auth_level, created_at, last_activity_at, expires_at, revoked_at
             FROM admin_sessions WHERE session_token_hash = :token_hash'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        return $row;
    }
}

final class WorkflowClock implements TwoFactorClock, RateLimitClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'));
    }
}

final class WorkflowRateLimitRepository implements RateLimitRepository
{
    public function countFailures(string $scope, string $keyHash, DateTimeImmutable $since): int { return 0; }
    public function recordFailure(string $scope, string $keyHash, DateTimeImmutable $occurredAt): void {}
    public function clearFailures(string $scope, string $keyHash): void {}
    public function lockedUntil(string $scope, string $keyHash): ?DateTimeImmutable { return null; }
    public function lock(string $scope, string $keyHash, DateTimeImmutable $until): void {}
}

final class NullAuditLog implements AuditLog
{
    public function append(AuditEvent $event): void {}
}
