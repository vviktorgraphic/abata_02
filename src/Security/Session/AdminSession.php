<?php

declare(strict_types=1);

namespace App\Security\Session;

final class AdminSession
{
    private const ADMIN_ID = 'admin_id';
    private const PENDING_ADMIN_ID = 'pending_admin_id';
    private const LAST_ACTIVITY = 'admin_last_activity';

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly SessionIdRotator $idRotator,
        private readonly Clock $clock,
        private readonly int $inactivityTimeoutSeconds = 900,
    ) {
        if ($this->inactivityTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('The inactivity timeout must be positive.');
        }
    }

    /** Starts a temporary, not-yet-authenticated state (for example while awaiting 2FA). */
    public function beginPendingAuthentication(int $adminId): void
    {
        $this->storage->start();
        $this->idRotator->rotate();
        $this->storage->remove(self::ADMIN_ID);
        $this->storage->set(self::PENDING_ADMIN_ID, $adminId);
        $this->storage->set(self::LAST_ACTIVITY, $this->clock->now());
    }

    /** Promotes the pending identity to a fully authenticated session. */
    public function authenticate(int $adminId): void
    {
        $this->storage->start();
        $this->idRotator->rotate();
        $this->storage->remove(self::PENDING_ADMIN_ID);
        $this->storage->set(self::ADMIN_ID, $adminId);
        $this->storage->set(self::LAST_ACTIVITY, $this->clock->now());
    }

    public function pendingAdminId(): ?int
    {
        $this->storage->start();
        return $this->activeId(self::PENDING_ADMIN_ID);
    }

    public function authenticatedAdminId(): ?int
    {
        $this->storage->start();
        return $this->activeId(self::ADMIN_ID);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticatedAdminId() !== null;
    }

    /** Destroys all session state and expires the session cookie. */
    public function logout(): void
    {
        $this->storage->start();
        $this->storage->destroy();
    }

    private function activeId(string $key): ?int
    {
        $id = $this->storage->get($key);
        $lastActivity = $this->storage->get(self::LAST_ACTIVITY);
        if (!is_int($id) || !is_int($lastActivity)) {
            return null;
        }

        if (($this->clock->now() - $lastActivity) >= $this->inactivityTimeoutSeconds) {
            $this->storage->destroy();
            return null;
        }

        $this->storage->set(self::LAST_ACTIVITY, $this->clock->now());
        return $id;
    }
}
