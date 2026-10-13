<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Booking\AdminManualBookingPreview;
use App\Application\Booking\AdminManualBookingPreviewSigner;
use App\Application\Booking\AdminManualBookingWorkflow;
use App\Application\Booking\AdminCustomPrice;
use App\Application\Booking\BookingClock;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingPersistenceResult;
use App\Application\Booking\BookingPreviewStale;
use App\Domain\Booking\BookingCreateRequest;
use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Pricing\PricingResult;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminBookingFlashStore;
use App\Http\Controller\Admin\AdminManualBookingController;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\RedirectResponse;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AdminManualBookingControllerTest extends TestCase
{
    public function testAnonymousRequestsRedirectBeforeWorkflowUse(): void
    {
        [$controller, $workflow, $csrf] = $this->fixture(null);

        self::assertInstanceOf(RedirectResponse::class, $controller->show());
        $response = $controller->preview(
            [...$this->form(), '_csrf' => $csrf->token()],
            'application/x-www-form-urlencoded',
            500,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/login', $response->location);
        self::assertSame(0, $workflow->previewCalls);
        self::assertSame(0, $workflow->createCalls);
    }

    public function testAuthenticatedFormExplainsAdminSemanticsAndHasNoLegalCheckboxes(): void
    {
        [$controller] = $this->fixture();

        $response = $controller->show();

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Új foglalás', $response->body);
        self::assertStringContainsString('adminisztrátor által rögzített foglalás', $response->body);
        self::assertStringNotContainsString('name="privacy_accepted"', $response->body);
        self::assertStringNotContainsString('name="booking_policy_accepted"', $response->body);
        self::assertStringNotContainsString('name="house_rules_accepted"', $response->body);
        self::assertStringContainsString('min="2047-06-10"', $response->body);
    }

    public function testPreviewRequiresCsrfAndNeverPersists(): void
    {
        [$controller, $workflow] = $this->fixture();

        $response = $controller->preview($this->form(), 'application/x-www-form-urlencoded', 500);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(403, $response->status);
        self::assertSame(0, $workflow->previewCalls);
        self::assertSame(0, $workflow->createCalls);
    }

    public function testPreviewRendersSharedPriceAndEscapesRetainedGuestDataWithoutPersisting(): void
    {
        [$controller, $workflow, $csrf] = $this->fixture();
        $form = [...$this->form(), 'contact_name' => '<script>alert(1)</script>', '_csrf' => $csrf->token()];

        $response = $controller->preview($form, 'application/x-www-form-urlencoded; charset=UTF-8', 900);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame(1, $workflow->previewCalls);
        self::assertSame(0, $workflow->createCalls);
        self::assertStringContainsString('Foglalás előnézete', $response->body);
        self::assertStringContainsString('45 000 Ft', $response->body);
        self::assertStringContainsString('5 000 Ft', $response->body);
        self::assertStringContainsString('50 000 Ft', $response->body);
        self::assertStringContainsString('name="preview_signature" value="', $response->body);
        self::assertStringContainsString('action="/admin/bookings/create/preview#booking-create-submit"', $response->body);
        self::assertSame(1, substr_count($response->body, 'id="booking-create-submit"'));
        self::assertMatchesRegularExpression('/<form id="booking-create-submit"[^>]*>.*<button type="submit">Foglalás rögzítése<\/button>/s', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        self::assertStringContainsString('Automatikus e-mail nem kerül kiküldésre.', $response->body);
    }

    public function testCustomTotalSurvivesPreviewAndIsBoundIntoSignedSave(): void
    {
        [$controller, $workflow, $csrf, $signer] = $this->fixture();
        $form = [...$this->form(), 'custom_total' => '48000', '_csrf' => $csrf->token()];

        $previewResponse = $controller->preview($form, 'application/x-www-form-urlencoded', 900);

        self::assertSame(200, $previewResponse->status);
        self::assertStringContainsString('Kalkulált végösszeg', $previewResponse->body);
        self::assertStringContainsString('Egyedi végösszeg', $previewResponse->body);
        self::assertStringContainsString('48 000 Ft', $previewResponse->body);
        self::assertStringContainsString('name="custom_total" value="48000"', $previewResponse->body);

        $request = $this->validator()->validate($form);
        $form += [
            'preview_signature' => $signer->sign($request, '50000.00', str_repeat('a', 64), '48000.00', '48000.00', 'HUF'),
            'expected_calculated_total' => '50000.00',
            'expected_pricing_hash' => str_repeat('a', 64),
            'expected_effective_total' => '48000.00',
            'expected_currency' => 'HUF',
        ];
        $tampered = [...$form, 'custom_total' => '47000'];
        self::assertSame(409, $controller->save($tampered, 'application/x-www-form-urlencoded', 1200)->status);
        self::assertSame(0, $workflow->createCalls);

        self::assertInstanceOf(RedirectResponse::class, $controller->save($form, 'application/x-www-form-urlencoded', 1200));
        self::assertSame(1, $workflow->createCalls);
        self::assertSame('48000.00', $workflow->lastExpectedTotal);
    }

    public function testInvalidCustomTotalIsRetainedAndRejectedBeforeWorkflow(): void
    {
        [$controller, $workflow, $csrf] = $this->fixture();
        $form = [...$this->form(), 'custom_total' => '12.50', '_csrf' => $csrf->token()];

        $response = $controller->preview($form, 'application/x-www-form-urlencoded', 900);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('egész, nem negatív forintösszeg', $response->body);
        self::assertStringContainsString('value="12.50"', $response->body);
        self::assertSame(0, $workflow->previewCalls);
    }

    public function testSignedSaveCreatesPendingBookingAndStoresOneTimeSuccessMessage(): void
    {
        [$controller, $workflow, $csrf, $signer, $flash] = $this->fixture();
        $form = [...$this->form(), '_csrf' => $csrf->token()];
        $request = $this->validator()->validate($form);
        $form += [
            'preview_signature' => $signer->sign($request, '50000.00', str_repeat('a', 64), null, '50000.00', 'HUF'),
            'expected_calculated_total' => '50000.00',
            'expected_pricing_hash' => str_repeat('a', 64),
            'expected_effective_total' => '50000.00',
            'expected_currency' => 'HUF',
        ];

        $response = $controller->save($form, 'application/x-www-form-urlencoded', 1200);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->status);
        self::assertSame('/admin/bookings/AB-20470610-TEST', $response->location);
        self::assertSame(1, $workflow->createCalls);
        self::assertSame(7, $workflow->lastAdminId);
        self::assertSame('50000.00', $workflow->lastExpectedTotal);
        self::assertSame('HUF', $workflow->lastExpectedCurrency);
        self::assertSame('A foglalás rögzítve.', $flash->consume('AB-20470610-TEST'));
        self::assertNull($flash->consume('AB-20470610-TEST'));
    }

    public function testTamperedPreviewPayloadOrPriceIsRejectedWithoutPersistence(): void
    {
        [$controller, $workflow, $csrf, $signer] = $this->fixture();
        $form = [...$this->form(), '_csrf' => $csrf->token()];
        $request = $this->validator()->validate($form);
        $form += [
            'preview_signature' => $signer->sign($request, '50000.00', str_repeat('a', 64), null, '50000.00', 'HUF'),
            'expected_calculated_total' => '50000.00',
            'expected_pricing_hash' => str_repeat('a', 64),
            'expected_effective_total' => '50001.00',
            'expected_currency' => 'HUF',
        ];

        $response = $controller->save($form, 'application/x-www-form-urlencoded', 1200);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(409, $response->status);
        self::assertStringContainsString('előnézet lejárt', $response->body);
        self::assertSame(0, $workflow->createCalls);
    }

    public function testAvailabilityChangeAfterPreviewRequiresANewPreview(): void
    {
        [$controller, $workflow, $csrf, $signer] = $this->fixture();
        $form = [...$this->form(), '_csrf' => $csrf->token()];
        $request = $this->validator()->validate($form);
        $form += [
            'preview_signature' => $signer->sign($request, '50000.00', str_repeat('a', 64), null, '50000.00', 'HUF'),
            'expected_calculated_total' => '50000.00',
            'expected_pricing_hash' => str_repeat('a', 64),
            'expected_effective_total' => '50000.00',
            'expected_currency' => 'HUF',
        ];
        $workflow->createError = new BookingConflict('changed');

        $response = $controller->save($form, 'application/x-www-form-urlencoded', 1200);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(409, $response->status);
        self::assertStringContainsString('foglaltság az előnézet óta megváltozott', $response->body);
        self::assertSame(1, $workflow->createCalls);
    }

    public function testPriceChangeAfterPreviewRequiresANewPreview(): void
    {
        [$controller, $workflow, $csrf, $signer] = $this->fixture();
        $form = [...$this->form(), '_csrf' => $csrf->token()];
        $request = $this->validator()->validate($form);
        $form += [
            'preview_signature' => $signer->sign($request, '50000.00', str_repeat('a', 64), null, '50000.00', 'HUF'),
            'expected_calculated_total' => '50000.00',
            'expected_pricing_hash' => str_repeat('a', 64),
            'expected_effective_total' => '50000.00',
            'expected_currency' => 'HUF',
        ];
        $workflow->createError = new BookingPreviewStale('Az ár időközben megváltozott. Kérj új előnézetet.');

        $response = $controller->save($form, 'application/x-www-form-urlencoded', 1200);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(409, $response->status);
        self::assertStringContainsString('Az ár időközben megváltozott. Kérj új előnézetet.', $response->body);
        self::assertSame(1, $workflow->createCalls);
    }

    public function testValidationErrorsRetainSafeInputAndDoNotShowAConfirmationForm(): void
    {
        [$controller, $workflow, $csrf] = $this->fixture();
        $form = [...$this->form(), 'email' => 'invalid', 'contact_name' => 'Őrzött & név', '_csrf' => $csrf->token()];

        $response = $controller->preview($form, 'application/x-www-form-urlencoded', 700);

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Érvényes e-mail-cím szükséges.', $response->body);
        self::assertStringContainsString('Őrzött &amp; név', $response->body);
        self::assertStringContainsString('action="/admin/bookings/create/preview#booking-create-submit"', $response->body);
        self::assertStringNotContainsString('id="booking-create-submit"', $response->body);
        self::assertStringNotContainsString('Foglalás rögzítése', $response->body);
        self::assertSame(0, $workflow->previewCalls);
    }

    /** @return array{AdminManualBookingController, ManualBookingWorkflowSpy, CsrfTokenManager, AdminManualBookingPreviewSigner, AdminBookingFlashStore} */
    private function fixture(?array $admin = ['id' => 7, 'name' => 'Admin']): array
    {
        $session = new ManualBookingArraySessionStorage();
        $csrf = new CsrfTokenManager($session);
        $auth = new ManualBookingAuthWorkflow($admin);
        $workflow = new ManualBookingWorkflowSpy();
        $signer = new AdminManualBookingPreviewSigner('feature-test-secret');
        $flash = new AdminBookingFlashStore($session);
        $view = new AdminView(
            dirname(__DIR__, 3) . '/templates',
            $csrf,
            static fn (): bool => $auth->currentAdmin() !== null,
        );
        $controller = new AdminManualBookingController(
            $auth,
            $view,
            $csrf,
            new AdminActionGuard($auth, $csrf, new ManualBookingRateLimiter()),
            $this->validator(),
            $workflow,
            $signer,
            new ManualBookingClock(),
            $flash,
        );

        return [$controller, $workflow, $csrf, $signer, $flash];
    }

    private function validator(): BookingCreateRequestValidator
    {
        return new BookingCreateRequestValidator(
            new DateTimeImmutable('2047-06-10', new DateTimeZone('Europe/Budapest')),
            minimumAdvanceDays: 0,
            requireLegalAcceptances: false,
        );
    }

    /** @return array<string,mixed> */
    private function form(): array
    {
        return [
            'arrival_date' => '2047-06-10',
            'departure_date' => '2047-06-12',
            'contact_name' => 'Teszt Vendég',
            'email' => 'guest@example.invalid',
            'phone' => '+36 30 123 4567',
            'adults' => '2',
            'children' => '1',
            'child_ages' => ['3'],
            'notes' => 'Admin csatorna',
            'custom_total' => '',
            'idempotency_key' => 'admin-feature-key-1234',
        ];
    }
}

final class ManualBookingWorkflowSpy implements AdminManualBookingWorkflow
{
    public int $previewCalls = 0;
    public int $createCalls = 0;
    public ?int $lastAdminId = null;
    public ?string $lastExpectedTotal = null;
    public ?string $lastExpectedCurrency = null;
    public ?\Throwable $createError = null;

    public function preview(BookingCreateRequest $request, ?AdminCustomPrice $customPrice = null): AdminManualBookingPreview
    {
        ++$this->previewCalls;

        $calculated = new PricingResult(
            '50000.00',
            '45000.00',
            '5000.00',
            'HUF',
            [],
            [],
            ['version' => 3],
        );
        $effective = $customPrice === null ? $calculated : (new \App\Application\Booking\AdminPricingOverride())->applyToResult($calculated, $customPrice);

        return new AdminManualBookingPreview($request, $calculated, $effective, $customPrice?->total, str_repeat('a', 64));
    }

    public function create(
        BookingCreateRequest $request,
        int $adminId,
        string $expectedCalculatedTotal,
        string $expectedPricingHash,
        ?AdminCustomPrice $customPrice,
        string $expectedEffectiveTotal,
        string $expectedCurrency,
    ): BookingPersistenceResult {
        ++$this->createCalls;
        $this->lastAdminId = $adminId;
        $this->lastExpectedTotal = $expectedEffectiveTotal;
        $this->lastExpectedCurrency = $expectedCurrency;
        if ($this->createError !== null) {
            throw $this->createError;
        }

        return new BookingPersistenceResult(91, 'AB-20470610-TEST', 'pending', '50000.00', 'HUF', false);
    }
}

final readonly class ManualBookingAuthWorkflow implements AdminAuthWorkflow
{
    /** @param array{id:int,name:string}|null $admin */
    public function __construct(private ?array $admin) {}
    public function login(string $email, string $password, array $requestContext = []): bool { return false; }
    public function verify(string $code, array $requestContext = []): bool { return false; }
    public function resend(array $requestContext = []): bool { return false; }
    public function logout(array $requestContext = []): void {}
    public function currentAdmin(): ?array { return $this->admin; }
}

final class ManualBookingArraySessionStorage implements SessionStorage
{
    /** @var array<string,mixed> */
    private array $data = [];
    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function destroy(): void { $this->data = []; }
}

final class ManualBookingRateLimiter implements AdminActionRateLimiter
{
    public function allow(int $adminId, string $action): bool { return true; }
}

final readonly class ManualBookingClock implements BookingClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2047-06-10 09:00:00', new DateTimeZone('Europe/Budapest'));
    }
}
