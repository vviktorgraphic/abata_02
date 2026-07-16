<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadataSanitizer;
use App\Application\Authentication\AuthenticationService;
use App\Application\Mail\Mailer;
use App\Application\Mail\TwoFactorMailRenderer;
use App\Application\TwoFactor\IssueTwoFactorCode;
use App\Application\TwoFactor\VerifyTwoFactorCode;
use App\Infrastructure\Persistence\Auth\AdminSessionRepository;
use App\Infrastructure\Persistence\Auth\PdoAdminCredentialRepository;
use App\Security\RateLimit\AuthenticationRateLimitPolicies;
use App\Security\RateLimit\RateLimiter;
use App\Security\Session\AdminSession;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final readonly class DefaultAdminAuthWorkflow implements AdminAuthWorkflow
{
    public function __construct(
        private AuthenticationService $authentication,
        private PdoAdminCredentialRepository $admins,
        private IssueTwoFactorCode $issueCode,
        private VerifyTwoFactorCode $verifyCode,
        private Mailer $mailer,
        private TwoFactorMailRenderer $mailRenderer,
        private AdminSession $session,
        private AdminSessionRepository $sessions,
        private RateLimiter $rateLimiter,
        private AuthenticationRateLimitPolicies $policies,
        private AuditLog $auditLog,
        private AuditMetadataSanitizer $auditMetadata,
        private PDO $pdo,
        private string $privacyPepper,
        private int $idleSeconds = 900,
    ) {}

    public function login(string $email, string $password, array $requestContext = []): bool
    {
        $ip = $requestContext['ip'] ?? 'unknown';
        $account = mb_strtolower(trim($email), 'UTF-8');
        if (!$this->rateLimiter->check($this->policies->loginByIp, $ip)->allowed
            || !$this->rateLimiter->check($this->policies->loginByAccount, $account === '' ? 'invalid' : $account)->allowed) {
            $this->audit('admin.login', 'rate_limited', null, $ip, ['reason_code' => 'rate_limit', 'auth_stage' => 'password']);
            return false;
        }

        $result = $this->authentication->checkCredentials($email, $password);
        if (!$result->accepted || $result->adminId === null || $result->normalizedEmail === null) {
            $this->rateLimiter->recordFailure($this->policies->loginByIp, $ip);
            $this->rateLimiter->recordFailure($this->policies->loginByAccount, $account === '' ? 'invalid' : $account);
            $this->audit('admin.login', 'rejected', null, $ip, ['reason_code' => 'invalid_credentials', 'auth_stage' => 'password']);
            return false;
        }

        try {
            $generated = $this->issueCode->issue($result->adminId);
            $this->mailer->send($this->mailRenderer->render($result->normalizedEmail, $generated->plaintext));
        } catch (Throwable) {
            $this->audit('admin.2fa.delivery', 'failed', $result->adminId, $ip, ['reason_code' => 'mail_transport', 'auth_stage' => 'two_factor']);
            return false;
        }

        $this->session->beginPendingAuthentication($result->adminId);
        $now = $this->now();
        $this->sessions->create($result->adminId, session_id(), 'two_factor_pending', $now, $now->modify('+' . $this->idleSeconds . ' seconds'));
        $this->rateLimiter->recordSuccess($this->policies->loginByIp, $ip);
        $this->rateLimiter->recordSuccess($this->policies->loginByAccount, $result->normalizedEmail);
        $this->audit('admin.login', 'password_accepted', $result->adminId, $ip, ['auth_stage' => 'password']);
        return true;
    }

    public function verify(string $code, array $requestContext = []): bool
    {
        $adminId = $this->activePendingAdminId();
        $ip = $requestContext['ip'] ?? 'unknown';
        if ($adminId === null || !$this->rateLimiter->check($this->policies->twoFactorVerify, (string) $adminId)->allowed) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $valid = $this->verifyCode->verify($adminId, $code);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        if (!$valid) {
            $this->rateLimiter->recordFailure($this->policies->twoFactorVerify, (string) $adminId);
            $this->audit('admin.2fa.verify', 'rejected', $adminId, $ip, ['reason_code' => 'invalid_code', 'auth_stage' => 'two_factor']);
            return false;
        }

        $oldToken = session_id();
        try {
            // Audit is mandatory before privilege promotion; failure leaves no authenticated session.
            $this->audit('admin.2fa.verify', 'accepted', $adminId, $ip, ['auth_stage' => 'two_factor']);
            $this->sessions->revoke($oldToken, $this->now());
            $this->session->authenticate($adminId);
            $now = $this->now();
            $this->sessions->create($adminId, session_id(), 'authenticated', $now, $now->modify('+' . $this->idleSeconds . ' seconds'));
        } catch (Throwable $exception) {
            $this->session->logout();
            throw $exception;
        }
        $this->rateLimiter->recordSuccess($this->policies->twoFactorVerify, (string) $adminId);
        return true;
    }

    public function resend(array $requestContext = []): bool
    {
        $adminId = $this->activePendingAdminId();
        $ip = $requestContext['ip'] ?? 'unknown';
        if ($adminId === null || !$this->rateLimiter->check($this->policies->twoFactorResend, (string) $adminId)->allowed) return false;
        $admin = $this->admins->findSummaryById($adminId);
        if ($admin === null) return false;
        try {
            $generated = $this->issueCode->issue($adminId);
            $this->mailer->send($this->mailRenderer->render($admin['email'], $generated->plaintext));
            return true;
        } catch (DomainException) {
            $this->rateLimiter->recordFailure($this->policies->twoFactorResend, (string) $adminId);
            return false;
        } catch (Throwable) {
            $this->audit('admin.2fa.delivery', 'failed', $adminId, $ip, ['reason_code' => 'mail_transport', 'auth_stage' => 'two_factor']);
            return false;
        }
    }

    public function logout(array $requestContext = []): void
    {
        $adminId = $this->session->authenticatedAdminId();
        $token = session_id();
        if ($token !== '') $this->sessions->revoke($token, $this->now());
        $this->session->logout();
        $this->audit('admin.logout', 'accepted', $adminId, $requestContext['ip'] ?? 'unknown', ['auth_stage' => 'authenticated']);
    }

    public function currentAdmin(): ?array
    {
        $adminId = $this->session->authenticatedAdminId();
        if ($adminId === null || session_id() === '') return null;
        $now = $this->now();
        if ($this->sessions->activeAdminId(session_id(), 'authenticated', $now) !== $adminId
            || !$this->sessions->touch(session_id(), $now, $now->modify('+' . $this->idleSeconds . ' seconds'))) {
            $this->session->logout();
            return null;
        }
        $admin = $this->admins->findSummaryById($adminId);
        return $admin === null ? null : ['id' => $admin['id'], 'name' => $admin['name']];
    }

    /** @param array<string, scalar|null> $metadata */
    private function audit(string $type, string $result, ?int $adminId, string $ip, array $metadata): void
    {
        $this->auditLog->append(new AuditEvent($type, $result, $this->now(), $this->auditMetadata->sanitize($metadata), $adminId, hash_hmac('sha256', $ip, $this->privacyPepper)));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'));
    }

    private function activePendingAdminId(): ?int
    {
        $adminId = $this->session->pendingAdminId();
        $token = session_id();
        if ($adminId === null || $token === '') return null;
        $now = $this->now();
        if ($this->sessions->activeAdminId($token, 'two_factor_pending', $now) !== $adminId
            || !$this->sessions->touch($token, $now, $now->modify('+' . $this->idleSeconds . ' seconds'))) {
            $this->session->logout();
            return null;
        }
        return $adminId;
    }
}
