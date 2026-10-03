<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditMetadata;
use App\Application\Authentication\AdminUserRepository;
use App\Domain\Authentication\EmailNormalizer;
use App\Infrastructure\Persistence\Auth\AdminSessionRepository;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Auth\PdoTwoFactorCodeStore;
use App\Security\Csrf\CsrfTokenManager;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final readonly class AdminUserController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private AdminUserRepository $repository,
        private AdminSessionRepository $sessions,
        private PdoTwoFactorCodeStore $codes,
        private PdoAuditLog $audit,
    ) {}

    public function index(?string $error = null, int $status = 200): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('users', [
            'users' => $this->repository->allForManagement(),
            'csrfToken' => $this->csrf->token(),
            'error' => $error,
        ]), $status);
    }

    public function createForm(?string $error = null, array $old = [], int $status = 200): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('user-create', [
            'csrfToken' => $this->csrf->token(), 'error' => $error, 'old' => $old,
        ]), $status);
    }

    public function create(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('admin_user.create', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        $old = ['name' => trim((string)($form['name'] ?? '')), 'email' => trim((string)($form['email'] ?? ''))];
        try {
            $name = trim((string)($form['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 190) throw new \InvalidArgumentException('A név kötelező és legfeljebb 190 karakter lehet.');
            $email = (new EmailNormalizer())->normalize((string)($form['email'] ?? ''));
            if ($email === null) throw new \InvalidArgumentException('Érvényes e-mail-cím szükséges.');
            $password = (string)($form['password'] ?? '');
            $confirmation = (string)($form['password_confirmation'] ?? '');
            if (strlen($password) < 12) throw new \InvalidArgumentException('A jelszó legalább 12 karakter legyen.');
            if (!hash_equals($password, $confirmation)) throw new \InvalidArgumentException('A jelszó és megerősítése nem egyezik.');
            if ($this->repository->findByNormalizedEmail($email) !== null) throw new \InvalidArgumentException('Az e-mail-cím már használatban van.');
            $id = $this->repository->createAdmin($name, $email, password_hash($password, PASSWORD_DEFAULT));
            $this->audit->append(new AuditEvent('admin_user.created', 'success', $this->now(), new AuditMetadata(['target_type'=>'admin','target_id'=>(string)$id,'target_admin_id'=>$id,'target_email'=>$email]), $authorization->admin['id']));
            return new RedirectResponse('/admin/users?created=1');
        } catch (PDOException) {
            return $this->createForm('A felhasználó nem hozható létre.', $old, 422);
        } catch (\InvalidArgumentException $error) {
            return $this->createForm($error->getMessage(), $old, 422);
        }
    }

    public function setActive(int $targetId, bool $active, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('admin_user.' . ($active ? 'activate' : 'deactivate'), $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        $actor = $authorization->admin['id'];
        if ($targetId === $actor && !$active) return $this->index('A saját aktív fiókja nem inaktiválható.', 422);
        if ($targetId < 1) return $this->index('A felhasználó nem található.', 404);
        if (!$active && $this->repository->countActive() <= 1) return $this->index('Az utolsó aktív admin nem inaktiválható.', 422);
        try {
            $changed = $this->repository->setActive($targetId, $active);
            if (!$changed) return $this->index('A felhasználó nem található vagy már ebben az állapotban van.', 422);
            $now = $this->now();
            if (!$active) { $this->sessions->revokeAllForAdmin($targetId, $now); $this->codes->invalidateAllForAdmin($targetId, $now); }
            $this->audit->append(new AuditEvent('admin_user.' . ($active ? 'activated' : 'deactivated'), 'success', $now, new AuditMetadata(['target_type'=>'admin','target_id'=>(string)$targetId,'target_admin_id'=>$targetId]), $actor));
            return new RedirectResponse('/admin/users?updated=1');
        } catch (\Throwable) {
            return $this->index('A felhasználó állapota nem módosítható.', 422);
        }
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')); }
}
