<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Booking\AdminDashboardClock;
use App\Application\Booking\AdminDashboardOverview;
use App\Application\Booking\AdminDashboardRepository;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\RedirectResponse;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AdminDashboardControllerTest extends TestCase
{
    public function testUnauthenticatedRequestRedirectsBeforeQueryingDashboardData(): void
    {
        $repository = new DashboardSpyRepository($this->emptyOverview());
        $controller = $this->controller(null, $repository, new FixedDashboardClock());

        $response = $controller->show();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/login', $response->location);
        self::assertSame(0, $repository->calls);
    }

    public function testAuthenticatedDashboardUsesBudapestDateAndRendersOperationalDataSafely(): void
    {
        $booking = [
            'reference' => 'OPS/<script>',
            'contact_name' => '<img src=x onerror=alert(1)>',
            'arrival_date' => '2046-06-10',
            'departure_date' => '2046-06-13',
            'nights' => 3,
            'adults' => 2,
            'children' => 1,
            'party_size' => 3,
        ];
        $overview = new AdminDashboardOverview(4, 1, 1, 2, [$booking], [$booking], [$booking], true);
        $repository = new DashboardSpyRepository($overview);
        $clock = new FixedDashboardClock(new DateTimeImmutable('2046-06-09 22:30:00', new DateTimeZone('UTC')));

        $response = $this->controller(['id' => 7, 'name' => 'Admin <owner>'], $repository, $clock)->show();

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame(1, $repository->calls);
        self::assertSame('2046-06-10', $repository->receivedToday?->format('Y-m-d'));
        self::assertSame('Europe/Budapest', $repository->receivedToday?->getTimezone()->getName());
        foreach (['Operatív áttekintés', 'Függő foglalási igények', 'Mai érkezők', 'Mai távozók', 'Jelenlegi vendégek', 'Következő érkezések', 'Gyorsműveletek'] as $text) {
            self::assertStringContainsString($text, $response->body);
        }
        self::assertStringContainsString('2046. 06. 10.', $response->body);
        self::assertStringContainsString('/admin/bookings/OPS%2F%3Cscript%3E', $response->body);
        self::assertStringContainsString('OPS/&lt;script&gt;', $response->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->body);
        self::assertStringNotContainsString('<img src=x', $response->body);
        self::assertStringNotContainsString('guest@example.invalid', $response->body);
        self::assertStringNotContainsString('+3612345678', $response->body);
        self::assertStringContainsString('arrival_from=2046-06-11', $response->body);
        self::assertStringContainsString('arrival_until=2046-06-17', $response->body);
    }

    public function testDashboardRendersNaturalEmptyStatesAndHidesOverflowLinkWhenNotNeeded(): void
    {
        $response = $this->controller(
            ['id' => 7, 'name' => 'Admin'],
            new DashboardSpyRepository($this->emptyOverview()),
            new FixedDashboardClock(),
        )->show();

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertStringContainsString('Ma nincs érkező vendég.', $response->body);
        self::assertStringContainsString('Ma nincs távozó vendég.', $response->body);
        self::assertStringContainsString('A következő hét napban nincs megerősített érkezés.', $response->body);
        self::assertStringNotContainsString('Összes közelgő foglalás', $response->body);
    }

    /** @param array{id: int, name: string}|null $admin */
    private function controller(?array $admin, AdminDashboardRepository $repository, AdminDashboardClock $clock): DashboardController
    {
        $storage = new DashboardArraySessionStorage();
        $csrf = new CsrfTokenManager($storage);
        $auth = new DashboardAuthWorkflow($admin);
        $view = new AdminView(
            dirname(__DIR__, 3) . '/templates',
            $csrf,
            static fn (): bool => $auth->currentAdmin() !== null,
        );

        return new DashboardController($auth, $view, $csrf, $repository, $clock);
    }

    private function emptyOverview(): AdminDashboardOverview
    {
        return new AdminDashboardOverview(0, 0, 0, 0, [], [], [], false);
    }
}

final class DashboardSpyRepository implements AdminDashboardRepository
{
    public int $calls = 0;
    public ?DateTimeImmutable $receivedToday = null;

    public function __construct(private readonly AdminDashboardOverview $overview)
    {
    }

    public function fetch(DateTimeImmutable $today): AdminDashboardOverview
    {
        ++$this->calls;
        $this->receivedToday = $today;

        return $this->overview;
    }
}

final readonly class FixedDashboardClock implements AdminDashboardClock
{
    public function __construct(private DateTimeImmutable $date = new DateTimeImmutable('2046-06-10', new DateTimeZone('Europe/Budapest')))
    {
    }

    public function today(): DateTimeImmutable
    {
        return $this->date;
    }
}

final readonly class DashboardAuthWorkflow implements AdminAuthWorkflow
{
    /** @param array{id: int, name: string}|null $admin */
    public function __construct(private ?array $admin)
    {
    }

    public function login(string $email, string $password, array $requestContext = []): bool { return false; }
    public function verify(string $code, array $requestContext = []): bool { return false; }
    public function resend(array $requestContext = []): bool { return false; }
    public function logout(array $requestContext = []): void {}
    public function currentAdmin(): ?array { return $this->admin; }
}

final class DashboardArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function destroy(): void { $this->data = []; }
}
