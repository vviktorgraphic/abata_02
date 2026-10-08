<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Booking\AdminMonthlyOccupancyBuilder;
use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Application\Booking\AdminMonthlyOccupancyRepository;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\MonthlyOccupancyController;
use App\Http\Controller\Admin\RedirectResponse;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AdminMonthlyOccupancyControllerTest extends TestCase
{
    public function test_requires_authenticated_admin(): void
    {
        $response = $this->controller(null, new MonthlyOccupancyFakeRepository())->index([]);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/login', $response->location);
    }

    public function test_invalid_month_returns_safe_422_without_querying_repository(): void
    {
        $repository = new MonthlyOccupancyFakeRepository();
        $response = $this->controller(['id' => 1, 'name' => 'Admin'], $repository)->index(['month' => '2026-13']);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('A megadott hónap érvénytelen.', $response->body);
        self::assertSame(0, $repository->calls);
    }

    public function test_renders_accessible_escaped_month_view_without_contact_details(): void
    {
        $repository = new MonthlyOccupancyFakeRepository([
            ['reference' => 'AB-<1>', 'contact_name' => '<Vendég>', 'status' => 'pending', 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09', 'legacy' => true],
            ['reference' => 'AB-COMPLETE', 'contact_name' => 'Korábbi vendég', 'status' => 'completed', 'arrival_date' => '2026-10-01', 'departure_date' => '2026-10-03', 'legacy' => false],
        ], [
            ['id' => 1, 'start_date' => '2026-10-10', 'end_date' => '2026-10-11', 'reason' => '<ok>', 'external_event_id' => null],
            ['id' => 2, 'start_date' => '2026-10-11', 'end_date' => '2026-10-12', 'reason' => '', 'external_event_id' => 9, 'event_summary' => '<Partner>', 'source_name' => '<Forrás>', 'provider' => 'google_calendar'],
        ]);
        $response = $this->controller(['id' => 1, 'name' => 'Admin'], $repository)->index(['month' => '2026-10']);

        self::assertSame(200, $response->status);
        self::assertSame(1, $repository->calls);
        self::assertSame('2026-10', $repository->month);
        self::assertSame(31, substr_count($response->body, 'data-date='));
        self::assertStringContainsString('Havi foglaltság</a>', $response->body);
        self::assertStringContainsString('2026. október', $response->body);
        self::assertStringContainsString('month=2026-09', $response->body);
        self::assertStringContainsString('month=2026-11', $response->body);
        self::assertStringContainsString('aria-current="date"', $response->body);
        self::assertStringContainsString('href="/admin/bookings/AB-%3C1%3E"', $response->body);
        self::assertStringContainsString('Függőben', $response->body);
        self::assertStringContainsString('Teljesült', $response->body);
        self::assertStringContainsString('status-completed', $response->body);
        self::assertStringContainsString('korábbi import', $response->body);
        self::assertStringContainsString('Google Calendar', $response->body);
        self::assertStringContainsString('&lt;Partner&gt;', $response->body);
        self::assertStringContainsString('&lt;Forrás&gt;', $response->body);
        self::assertStringContainsString('&lt;ok&gt;', $response->body);
        self::assertStringContainsString('&lt;Vendég&gt;', $response->body);
        self::assertStringNotContainsString('<Vendég>', $response->body);
        self::assertStringNotContainsString('guest@example', $response->body);
        self::assertStringContainsString('role="region"', $response->body);
        self::assertSame(4, substr_count($response->body, 'rel="prev"') + substr_count($response->body, 'rel="next"'));
    }

    public function test_non_current_month_offers_current_budapest_month_link(): void
    {
        $response = $this->controller(['id' => 1, 'name' => 'Admin'], new MonthlyOccupancyFakeRepository())->index(['month' => '2026-09']);

        self::assertStringContainsString('Mai hónap', $response->body);
        self::assertStringContainsString('month=2026-10', $response->body);
    }

    private function controller(?array $admin, AdminMonthlyOccupancyRepository $repository): MonthlyOccupancyController
    {
        return new MonthlyOccupancyController(
            new MonthlyOccupancyAuthWorkflow($admin),
            new AdminView(dirname(__DIR__, 3) . '/templates'),
            $repository,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-08 12:00:00+02:00'),
        );
    }
}

final class MonthlyOccupancyFakeRepository implements AdminMonthlyOccupancyRepository
{
    public int $calls = 0;
    public ?string $month = null;

    public function __construct(private array $bookings = [], private array $blocks = []) {}

    public function fetch(AdminMonthlyOccupancyQuery $query): array
    {
        ++$this->calls;
        $this->month = $query->month;
        return (new AdminMonthlyOccupancyBuilder())->build($query, $this->bookings, $this->blocks);
    }
}

final class MonthlyOccupancyAuthWorkflow implements AdminAuthWorkflow
{
    public function __construct(private ?array $admin) {}
    public function login(string $email, string $password, array $requestContext = []): bool { return false; }
    public function verify(string $code, array $requestContext = []): bool { return false; }
    public function resend(array $requestContext = []): bool { return false; }
    public function logout(array $requestContext = []): void {}
    public function currentAdmin(): ?array { return $this->admin; }
}
