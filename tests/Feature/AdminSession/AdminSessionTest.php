<?php

declare(strict_types=1);

namespace Tests\Feature\AdminSession;

use App\Security\Session\AdminSession;
use App\Security\Session\Clock;
use App\Security\Session\SessionIdRotator;
use App\Security\Session\SessionStorage;
use PHPUnit\Framework\TestCase;

final class AdminSessionTest extends TestCase
{
    public function testPendingAuthenticationIsNotAnAuthenticatedSession(): void
    {
        [$session, $storage, $rotator] = $this->sessionAt(1_000);

        $session->beginPendingAuthentication(42);

        self::assertSame(42, $session->pendingAdminId());
        self::assertNull($session->authenticatedAdminId());
        self::assertFalse($session->isAuthenticated());
        self::assertSame(1, $rotator->rotations);
        self::assertFalse($storage->destroyed);
    }

    public function testAuthenticationPromotesStateAndRotatesSessionId(): void
    {
        [$session, , $rotator] = $this->sessionAt(1_000);
        $session->beginPendingAuthentication(42);

        $session->authenticate(42);

        self::assertNull($session->pendingAdminId());
        self::assertSame(42, $session->authenticatedAdminId());
        self::assertSame(2, $rotator->rotations);
    }

    public function testActivityBeforeFifteenMinutesRefreshesTheDeadline(): void
    {
        [$session, $storage, , $clock] = $this->sessionAt(1_000);
        $session->authenticate(42);
        $clock->timestamp = 1_899;

        self::assertSame(42, $session->authenticatedAdminId());
        self::assertSame(1_899, $storage->values['admin_last_activity']);
    }

    public function testFifteenMinutesOfInactivityDestroysTheSession(): void
    {
        [$session, $storage, , $clock] = $this->sessionAt(1_000);
        $session->authenticate(42);
        $clock->timestamp = 1_900;

        self::assertNull($session->authenticatedAdminId());
        self::assertTrue($storage->destroyed);
        self::assertSame([], $storage->values);
    }

    public function testLogoutDestroysPendingOrAuthenticatedState(): void
    {
        [$session, $storage] = $this->sessionAt(1_000);
        $session->beginPendingAuthentication(42);

        $session->logout();

        self::assertTrue($storage->destroyed);
        self::assertSame([], $storage->values);
    }

    /** @return array{AdminSession, InMemorySessionStorage, RecordingSessionIdRotator, MutableClock} */
    private function sessionAt(int $timestamp): array
    {
        $storage = new InMemorySessionStorage();
        $rotator = new RecordingSessionIdRotator();
        $clock = new MutableClock($timestamp);
        return [new AdminSession($storage, $rotator, $clock), $storage, $rotator, $clock];
    }
}

final class MutableClock implements Clock
{
    public function __construct(public int $timestamp)
    {
    }

    public function now(): int
    {
        return $this->timestamp;
    }
}

final class RecordingSessionIdRotator implements SessionIdRotator
{
    public int $rotations = 0;

    public function rotate(): void
    {
        ++$this->rotations;
    }
}

final class InMemorySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    public array $values = [];
    public bool $destroyed = false;

    public function start(): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function destroy(): void
    {
        $this->destroyed = true;
        $this->values = [];
    }
}
