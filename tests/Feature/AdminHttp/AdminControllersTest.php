<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminSessionEstablishmentFailed;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\LoginController;
use App\Http\Controller\Admin\LogoutController;
use App\Http\Controller\Admin\RedirectResponse;
use App\Http\Controller\Admin\SecurityHeaders;
use App\Http\Controller\Admin\TwoFactorController;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PHPUnit\Framework\TestCase;

final class AdminControllersTest extends TestCase
{
    private AdminView $view;
    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $this->csrf = new CsrfTokenManager(new ArraySessionStorage());
        $this->view = new AdminView(dirname(__DIR__, 3) . '/templates', $this->csrf);
    }

    public function test_login_page_contains_accessible_secure_fields_and_branding(): void
    {
        $response = (new LoginController(new FakeAdminAuthWorkflow(), $this->view, $this->csrf))->show();

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertStringContainsString('A Bata', $response->body);
        self::assertStringContainsString('autocomplete="username"', $response->body);
        self::assertStringContainsString('autocomplete="current-password"', $response->body);
        self::assertStringContainsString('<label for="email">', $response->body);
        self::assertStringContainsString('name="_csrf"', $response->body);
    }

    public function test_rejected_login_uses_generic_error_and_does_not_echo_input(): void
    {
        $response = (new LoginController(new FakeAdminAuthWorkflow(loginAccepted: false), $this->view, $this->csrf))
            ->submit(['_csrf' => $this->csrf->token(), 'email' => '<script>secret@example.test</script>', 'password' => 'super-secret']);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(422, $response->status);
        self::assertStringNotContainsString('secret@example.test', $response->body);
        self::assertStringNotContainsString('super-secret', $response->body);
        self::assertStringContainsString('nem sikerült bejelentkezni', $response->body);
    }

    public function test_accepted_login_redirects_only_to_local_verification_route(): void
    {
        $response = (new LoginController(new FakeAdminAuthWorkflow(loginAccepted: true), $this->view, $this->csrf))
            ->submit(['_csrf' => $this->csrf->token(), 'email' => 'admin@example.test', 'password' => 'irrelevant']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/2fa', $response->location);
        self::assertSame(303, $response->status);
    }

    public function test_two_factor_page_exposes_numeric_one_time_code_semantics(): void
    {
        $response = (new TwoFactorController(new FakeAdminAuthWorkflow(), $this->view, $this->csrf))->show();

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertStringContainsString('inputmode="numeric"', $response->body);
        self::assertStringContainsString('autocomplete="one-time-code"', $response->body);
        self::assertStringContainsString('pattern="[0-9]{6}"', $response->body);
        self::assertStringContainsString('action="/admin/2fa/verify"', $response->body);
        self::assertStringContainsString('action="/admin/2fa/resend"', $response->body);
        self::assertStringNotContainsString('action="/admin/verify', $response->body);
        self::assertSame(2, substr_count($response->body, 'name="_csrf"'));
    }

    public function test_successful_two_factor_rotatesCsrfAndRedirectsToDashboard(): void
    {
        $workflow = new FakeAdminAuthWorkflow(verifyAccepted: true);
        $controller = new TwoFactorController($workflow, $this->view, $this->csrf);
        $before = $this->csrf->token();

        $response = $controller->verify(['_csrf' => $before, 'code' => '123456']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin', $response->location);
        self::assertNotSame($before, $this->csrf->token());
        self::assertSame(1, $workflow->verifyCalls);
    }

    public function test_session_establishment_failureShowsSafeUserMessageInsteadOfSilentRedirect(): void
    {
        $controller = new TwoFactorController(new FailingSessionAuthWorkflow(), $this->view, $this->csrf);

        $response = $controller->verify(['_csrf' => $this->csrf->token(), 'code' => '123456']);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(500, $response->status);
        self::assertStringContainsString('munkamenet nem hozható létre', $response->body);
        self::assertStringNotContainsString('Authenticated admin session establishment failed', $response->body);
    }

    public function test_every_auth_post_rejects_a_missing_or_invalid_csrf_token(): void
    {
        $workflow = new FakeAdminAuthWorkflow(loginAccepted: true);
        $login = new LoginController($workflow, $this->view, $this->csrf);
        $twoFactor = new TwoFactorController($workflow, $this->view, $this->csrf);

        $responses = [
            $login->submit(['email' => 'admin@example.test', 'password' => 'secret']),
            $twoFactor->verify(['_csrf' => 'invalid', 'code' => '123456']),
            $twoFactor->resend([]),
        ];

        foreach ($responses as $response) {
            self::assertInstanceOf(HtmlResponse::class, $response);
            self::assertSame(403, $response->status);
            self::assertStringContainsString('nem hajtható végre', $response->body);
        }
        self::assertSame(0, $workflow->loginCalls);
        self::assertSame(0, $workflow->verifyCalls);
        self::assertSame(0, $workflow->resendCalls);
    }

    public function test_dashboard_requires_an_authenticated_admin_and_escapes_name(): void
    {
        $anonymous = (new DashboardController(new FakeAdminAuthWorkflow(), $this->view, $this->csrf))->show();
        self::assertInstanceOf(RedirectResponse::class, $anonymous);
        self::assertSame('/admin/login', $anonymous->location);

        $authenticated = (new DashboardController(
            new FakeAdminAuthWorkflow(admin: ['id' => 7, 'name' => '<img src=x onerror=alert(1)>']),
            $this->view,
            $this->csrf,
        ))->show();
        self::assertInstanceOf(HtmlResponse::class, $authenticated);
        self::assertStringNotContainsString('<img src=x', $authenticated->body);
        self::assertStringContainsString('&lt;img src=x', $authenticated->body);
        self::assertStringContainsString('name="_csrf"', $authenticated->body);
    }

    public function testAuthenticatedLayoutShowsAccessiblePostLogoutAndControllerRequiresCsrf():void
    {
        $workflow=new FakeAdminAuthWorkflow(admin:['id'=>7,'name'=>'Admin']);
        $view=new AdminView(dirname(__DIR__,3).'/templates',$this->csrf);
        $dashboard=(new DashboardController($workflow,$view,$this->csrf))->show();
        self::assertStringContainsString('action="/admin/logout"',$dashboard->body);
        self::assertStringContainsString('title="Kijelentkezés"',$dashboard->body);
        self::assertStringContainsString('aria-label="Kijelentkezés"',$dashboard->body);
        self::assertStringContainsString('header-logout',$dashboard->body);
        $controller=new LogoutController($workflow,$this->csrf,$view);
        self::assertSame(403,$controller->submit([])->status);self::assertSame(0,$workflow->logoutCalls);
        $response=$controller->submit(['_csrf'=>$this->csrf->token()]);
        self::assertInstanceOf(RedirectResponse::class,$response);self::assertSame('/admin/login',$response->location);self::assertSame(1,$workflow->logoutCalls);
        $routes=(string)file_get_contents(dirname(__DIR__,3).'/public/index.php');
        self::assertStringNotContainsString("->get('/admin/logout'",$routes);
    }

    public function test_admin_security_headers_prevent_storage_embedding_and_sniffing(): void
    {
        $headers = SecurityHeaders::admin();

        self::assertSame('no-store, max-age=0', $headers['Cache-Control']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        self::assertStringNotContainsString('unsafe-inline', $headers['Content-Security-Policy']);

        $common = SecurityHeaders::common();
        self::assertSame('DENY', $common['X-Frame-Options']);
        self::assertSame('nosniff', $common['X-Content-Type-Options']);
        self::assertSame('strict-origin-when-cross-origin', $common['Referrer-Policy']);
    }

    public function test_hsts_contract_requires_both_production_and_https(): void
    {
        self::assertSame([], SecurityHeaders::transport('development', true, 0));
        self::assertSame([], SecurityHeaders::transport('production', false, 31536000));
        self::assertSame(
            ['Strict-Transport-Security' => 'max-age=31536000'],
            SecurityHeaders::transport('production', true, 31536000),
        );
    }

    public function test_redirect_response_rejects_external_and_protocol_relative_targets(): void
    {
        foreach (['https://attacker.example', '//attacker.example'] as $target) {
            try {
                new RedirectResponse($target);
                self::fail('Unsafe redirect target was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

final class FakeAdminAuthWorkflow implements AdminAuthWorkflow
{
    public int $loginCalls = 0;
    public int $verifyCalls = 0;
    public int $resendCalls = 0;
    public int $logoutCalls = 0;

    /** @param array{id: int, name: string}|null $admin */
    public function __construct(
        private bool $loginAccepted = false,
        private ?array $admin = null,
        private bool $verifyAccepted = false,
    )
    {
    }

    public function login(string $email, string $password, array $requestContext = []): bool { ++$this->loginCalls; return $this->loginAccepted; }
    public function verify(string $code, array $requestContext = []): bool { ++$this->verifyCalls; return $this->verifyAccepted; }
    public function resend(array $requestContext = []): bool { ++$this->resendCalls; return false; }
    public function logout(array $requestContext = []): void { ++$this->logoutCalls; }
    public function currentAdmin(): ?array { return $this->admin; }
}

final class FailingSessionAuthWorkflow implements AdminAuthWorkflow
{
    public function login(string $email, string $password, array $requestContext = []): bool { return false; }
    public function verify(string $code, array $requestContext = []): bool
    {
        throw AdminSessionEstablishmentFailed::afterSuccessfulTwoFactor(new \RuntimeException('test infrastructure failure'));
    }
    public function resend(array $requestContext = []): bool { return false; }
    public function logout(array $requestContext = []): void {}
    public function currentAdmin(): ?array { return null; }
}

final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function destroy(): void { $this->data = []; }
}
