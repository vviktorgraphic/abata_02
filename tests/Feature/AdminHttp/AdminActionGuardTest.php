<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\RedirectResponse;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PHPUnit\Framework\TestCase;

final class AdminActionGuardTest extends TestCase
{
    public function test_anonymous_request_is_redirected_before_security_inputs_are_processed(): void
    {
        $limiter = new GuardRateLimiter();
        $guard = $this->guard(null, $limiter, $csrf);
        $result = $guard->authorizeForm('booking.confirm', [], null, null);

        self::assertFalse($result->allowed());
        self::assertInstanceOf(RedirectResponse::class, $result->rejection);
        self::assertSame(0, $limiter->calls);
    }

    public function test_rejects_bad_content_type_unknown_or_oversized_body_and_bad_csrf(): void
    {
        $limiter = new GuardRateLimiter();
        $guard = $this->guard(['id' => 7, 'name' => 'Admin'], $limiter, $csrf);
        $token = $csrf->token();

        $cases = [
            [$guard->authorizeForm('booking.confirm', ['_csrf' => $token], 'application/json', 20), 415],
            [$guard->authorizeForm('booking.confirm', ['_csrf' => $token], 'application/x-www-form-urlencoded', null), 413],
            [$guard->authorizeForm('booking.confirm', ['_csrf' => $token], 'application/x-www-form-urlencoded', AdminActionGuard::MAX_BODY_BYTES + 1), 413],
            [$guard->authorizeForm('booking.confirm', ['_csrf' => 'bad'], 'application/x-www-form-urlencoded', 20), 403],
        ];
        foreach ($cases as [$result, $status]) {
            self::assertInstanceOf(HtmlResponse::class, $result->rejection);
            self::assertSame($status, $result->rejection->status);
        }
        self::assertSame(0, $limiter->calls);
    }

    public function test_validates_and_normalizes_note_before_rate_limit_and_business_code(): void
    {
        $limiter = new GuardRateLimiter();
        $guard = $this->guard(['id' => 7, 'name' => 'Admin'], $limiter, $csrf);
        $result = $guard->authorizeForm('booking.confirm', [
            '_csrf' => $csrf->token(),
            'admin_note' => '  ellenőrizve  ',
            'status' => 'confirmed', // ignored: prevents mass assignment through the guard contract
        ], 'Application/X-Www-Form-Urlencoded; charset=UTF-8', 80);

        self::assertTrue($result->allowed());
        self::assertSame(7, $result->admin['id']);
        self::assertSame('ellenőrizve', $result->adminNote);
        self::assertSame(['7:booking.confirm'], $limiter->keys);
    }

    public function test_rejects_non_string_and_oversized_note_and_rate_limited_action(): void
    {
        $limiter = new GuardRateLimiter();
        $guard = $this->guard(['id' => 7, 'name' => 'Admin'], $limiter, $csrf);
        $base = ['_csrf' => $csrf->token()];

        $wrongType = $guard->authorizeForm('booking.confirm', $base + ['admin_note' => ['x']], 'application/x-www-form-urlencoded', 20);
        $tooLong = $guard->authorizeForm('booking.confirm', $base + ['admin_note' => str_repeat('á', 501)], 'application/x-www-form-urlencoded', 1100);
        self::assertSame(422, $wrongType->rejection->status);
        self::assertSame(422, $tooLong->rejection->status);

        $limiter->allowed = false;
        $limited = $guard->authorizeForm('booking.confirm', $base, 'application/x-www-form-urlencoded', 20);
        self::assertSame(429, $limited->rejection->status);
    }

    /** @param array{id: int, name: string}|null $admin */
    private function guard(?array $admin, GuardRateLimiter $limiter, ?CsrfTokenManager &$csrf): AdminActionGuard
    {
        $csrf = new CsrfTokenManager(new GuardSessionStorage());
        return new AdminActionGuard(new GuardAuthWorkflow($admin), $csrf, $limiter);
    }
}

final class GuardRateLimiter implements AdminActionRateLimiter
{
    public bool $allowed = true;
    public int $calls = 0;
    /** @var list<string> */ public array $keys = [];
    public function allow(int $adminId, string $action): bool { ++$this->calls; $this->keys[] = $adminId . ':' . $action; return $this->allowed; }
}

final class GuardAuthWorkflow implements AdminAuthWorkflow
{
    /** @param array{id: int, name: string}|null $admin */
    public function __construct(private ?array $admin) {}
    public function login(string $email, string $password, array $requestContext = []): bool { return false; }
    public function verify(string $code, array $requestContext = []): bool { return false; }
    public function resend(array $requestContext = []): bool { return false; }
    public function logout(array $requestContext = []): void {}
    public function currentAdmin(): ?array { return $this->admin; }
}

final class GuardSessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */ private array $data = [];
    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function destroy(): void { $this->data = []; }
}
