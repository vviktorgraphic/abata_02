<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Mail\BookingStatusMailRenderer;
use App\Application\Mail\BookingStatusNotificationDispatcher;
use App\Application\Mail\InMemoryMailer;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\BookingManagementController;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\RedirectResponse;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\PdoBookingStatusNotificationOutbox;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfirmWithoutPaymentRequestControllerTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    private int $bookingId;
    private string $reference;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->pdo->prepare("INSERT INTO admins(email,password_hash,name) VALUES(:email,'hash','Exceptional Confirm Admin')")
            ->execute(['email' => 'exceptional-confirm-' . bin2hex(random_bytes(5)) . '@example.invalid']);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->reference = 'NO-PAY-' . strtoupper(bin2hex(random_bytes(5)));
        $this->pdo->prepare(
            "INSERT INTO bookings(reference,status,arrival_date,departure_date,guest_name,guest_email,adults,children,total_amount,currency)
             VALUES(:reference,'pending','2045-04-10','2045-04-13','Exceptional Guest','exceptional@example.invalid',2,0,60000.00,'HUF')"
        )->execute(['reference' => $this->reference]);
        $this->bookingId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO booking_pricing_snapshots(booking_id,snapshot) VALUES(:id,:snapshot)')
            ->execute([
                'id' => $this->bookingId,
                'snapshot' => json_encode([
                    'version' => 4, 'accommodation_fee' => '60000.00', 'taxes' => '0.00',
                    'total' => '60000.00', 'currency' => 'HUF', 'line_items' => [],
                ], JSON_THROW_ON_ERROR),
            ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->bookingId)) {
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type='booking' AND target_id=:id")
                ->execute(['id' => (string) $this->bookingId]);
            $this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id' => $this->bookingId]);
        }
        if (isset($this->adminId)) {
            $this->pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id' => $this->adminId]);
        }
    }

    public function testPromptRequiresAuthenticationAndCsrf(): void
    {
        [$anonymous, $anonymousCsrf] = $this->controller(null);
        $anonymousForm = ['_csrf' => $anonymousCsrf->token()];
        $redirect = $anonymous->promptConfirmWithoutPaymentRequest(
            $this->reference, $anonymousForm, 'application/x-www-form-urlencoded', 100
        );
        self::assertInstanceOf(RedirectResponse::class, $redirect);
        self::assertSame('/admin/login', $redirect->location);
        $directRedirect = $anonymous->confirmWithoutPaymentRequest(
            $this->reference, $anonymousForm, 'application/x-www-form-urlencoded', 100
        );
        self::assertInstanceOf(RedirectResponse::class, $directRedirect);
        self::assertSame('/admin/login', $directRedirect->location);

        [$authenticated] = $this->controller(['id' => $this->adminId, 'name' => 'Admin']);
        $forbidden = $authenticated->promptConfirmWithoutPaymentRequest(
            $this->reference, ['_csrf' => 'invalid'], 'application/x-www-form-urlencoded', 100
        );
        self::assertSame(403, $forbidden->status);
        $directForbidden = $authenticated->confirmWithoutPaymentRequest(
            $this->reference, ['_csrf' => 'invalid'], 'application/x-www-form-urlencoded', 100
        );
        self::assertSame(403, $directForbidden->status);
        self::assertSame('pending', $this->bookingStatus());
        self::assertSame(0, $this->outboxCount());
    }

    public function testPromptIsReadOnlyAndShowsTheExplicitRiskSummary(): void
    {
        [$controller, $csrf, $mailer, $limiter] = $this->controller(['id' => $this->adminId, 'name' => 'Admin']);
        $response = $controller->promptConfirmWithoutPaymentRequest(
            $this->reference, ['_csrf' => $csrf->token()], 'application/x-www-form-urlencoded', 100
        );

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Biztosan megerősíted ezt a foglalást díjbekérő és előleg nélkül?', $response->body);
        self::assertStringContainsString($this->reference, $response->body);
        self::assertStringContainsString('2045-04-10', $response->body);
        self::assertStringContainsString('2045-04-13', $response->body);
        self::assertStringContainsString('60 000 Ft', $response->body);
        self::assertStringContainsString('Díjbekérő nem kerül kiküldésre.', $response->body);
        self::assertStringContainsString('Előleg nem kerül rögzítésre.', $response->body);
        self::assertStringContainsString('A vendég foglalás-visszaigazoló e-mailt kap.', $response->body);
        self::assertStringContainsString('Igen, megerősítem díjbekérő nélkül', $response->body);
        self::assertStringContainsString('>Mégse</a>', $response->body);
        self::assertSame('pending', $this->bookingStatus());
        self::assertSame(0, $this->outboxCount());
        self::assertSame(0, $this->historyCount());
        self::assertCount(0, $mailer->messages());
        self::assertContains('booking.confirm_without_payment_prompt', $limiter->actions);
    }

    public function testDirectFinalPostConfirmsAndSendsTheNormalConfirmationExactlyOnce(): void
    {
        [$controller, $csrf, $mailer, $limiter] = $this->controller(['id' => $this->adminId, 'name' => 'Admin']);
        $form = ['_csrf' => $csrf->token()];

        $first = $controller->confirmWithoutPaymentRequest(
            $this->reference, $form, 'application/x-www-form-urlencoded', 100
        );
        $second = $controller->confirmWithoutPaymentRequest(
            $this->reference, $form, 'application/x-www-form-urlencoded', 100
        );

        self::assertInstanceOf(RedirectResponse::class, $first);
        self::assertSame('/admin/bookings/' . rawurlencode($this->reference) . '?result=confirmed_without_payment', $first->location);
        self::assertInstanceOf(HtmlResponse::class, $second);
        self::assertSame(409, $second->status);
        self::assertSame('confirmed', $this->bookingStatus());
        self::assertSame(1, $this->historyCount());
        self::assertSame(1, $this->outboxCount('booking_confirmed'));
        self::assertSame(0, $this->outboxCount('booking_payment_request'));
        self::assertCount(1, $mailer->messages());
        self::assertSame('Foglalás visszaigazolás', $mailer->messages()[0]->subject);
        self::assertContains('booking.confirm_without_payment', $limiter->actions);
    }

    /** @return iterable<string,array{string}> */
    public static function paymentStates(): iterable
    {
        foreach (['pending', 'processing', 'sent', 'failed'] as $status) yield $status => [$status];
    }

    #[DataProvider('paymentStates')]
    public function testFinalPostRejectsEveryExistingPaymentRequestStateWithDeterministicConflict(string $status): void
    {
        $this->insertPaymentRequest($status);
        [$controller, $csrf, $mailer] = $this->controller(['id' => $this->adminId, 'name' => 'Admin']);

        $response = $controller->confirmWithoutPaymentRequest(
            $this->reference, ['_csrf' => $csrf->token()], 'application/x-www-form-urlencoded', 100
        );

        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertSame(409, $response->status);
        self::assertStringContainsString(
            'Ehhez a foglaláshoz már tartozik díjbekérő-küldési folyamat. Használd a normál megerősítést.',
            $response->body
        );
        self::assertSame('pending', $this->bookingStatus());
        self::assertSame(0, $this->historyCount());
        self::assertSame(1, $this->outboxCount());
        self::assertCount(0, $mailer->messages());
    }

    public function testNormalConfirmationStillRequiresSentPaymentRequestAndThenSendsConfirmation(): void
    {
        [$controller, $csrf, $mailer] = $this->controller(['id' => $this->adminId, 'name' => 'Admin']);
        $form = ['_csrf' => $csrf->token()];
        self::assertSame(
            409,
            $controller->transition($this->reference, 'confirm', $form, 'application/x-www-form-urlencoded', 100)->status
        );
        self::assertSame('pending', $this->bookingStatus());
        self::assertCount(0, $mailer->messages());

        $this->insertPaymentRequest('sent');
        $response = $controller->transition(
            $this->reference, 'confirm', $form, 'application/x-www-form-urlencoded', 100
        );
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('confirmed', $this->bookingStatus());
        self::assertSame(1, $this->outboxCount('booking_confirmed'));
        self::assertCount(1, $mailer->messages());
    }

    /** @param array{id:int,name:string}|null $admin @return array{BookingManagementController,CsrfTokenManager,InMemoryMailer,ConfirmWithoutPaymentLimiter} */
    private function controller(?array $admin): array
    {
        $auth = new ConfirmWithoutPaymentAuth($admin);
        $session = new ConfirmWithoutPaymentSession();
        $csrf = new CsrfTokenManager($session);
        $limiter = new ConfirmWithoutPaymentLimiter();
        $mailer = new InMemoryMailer();
        $notifications = new BookingStatusNotificationDispatcher(
            new PdoBookingStatusNotificationOutbox($this->pdo),
            new BookingStatusMailRenderer(
                dirname(__DIR__, 3) . '/templates/email', 'noreply@example.invalid', 'A Bata',
                'info@example.invalid', 'A Bata'
            ),
            $mailer,
        );
        return [new BookingManagementController(
            auth: $auth,
            view: new AdminView(dirname(__DIR__, 3) . '/templates', $csrf, static fn (): bool => $auth->currentAdmin() !== null),
            csrf: $csrf,
            guard: new AdminActionGuard($auth, $csrf, $limiter),
            queries: new PdoAdminBookingQueryRepository($this->pdo),
            transitions: new TransactionalBookingRepository($this->pdo),
            notifications: $notifications,
        ), $csrf, $mailer, $limiter];
    }

    private function insertPaymentRequest(string $status): void
    {
        $this->pdo->prepare(
            "INSERT INTO email_outbox(booking_id,message_type,recipient,subject,payload,status,attempts,sent_at)
             VALUES(:id,'booking_payment_request','exceptional@example.invalid','Payment','{}',:status,0,
                    IF(:sent_status='sent',CURRENT_TIMESTAMP,NULL))"
        )->execute(['id' => $this->bookingId, 'status' => $status, 'sent_status' => $status]);
    }

    private function bookingStatus(): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM bookings WHERE id=:id');
        $statement->execute(['id' => $this->bookingId]);
        return (string) $statement->fetchColumn();
    }

    private function outboxCount(?string $messageType = null): int
    {
        $sql = 'SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id';
        $parameters = ['id' => $this->bookingId];
        if ($messageType !== null) {
            $sql .= ' AND message_type=:message_type';
            $parameters['message_type'] = $messageType;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    private function historyCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM booking_status_history WHERE booking_id=:id');
        $statement->execute(['id' => $this->bookingId]);
        return (int) $statement->fetchColumn();
    }
}

final readonly class ConfirmWithoutPaymentAuth implements AdminAuthWorkflow
{
    /** @param array{id:int,name:string}|null $admin */
    public function __construct(private ?array $admin) {}
    public function login(string $email, string $password, array $requestContext = []): bool { return false; }
    public function verify(string $code, array $requestContext = []): bool { return false; }
    public function resend(array $requestContext = []): bool { return false; }
    public function logout(array $requestContext = []): void {}
    public function currentAdmin(): ?array { return $this->admin; }
}

final class ConfirmWithoutPaymentSession implements SessionStorage
{
    /** @var array<string,mixed> */ private array $data = [];
    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function destroy(): void { $this->data = []; }
}

final class ConfirmWithoutPaymentLimiter implements AdminActionRateLimiter
{
    /** @var list<string> */ public array $actions = [];
    public function allow(int $adminId, string $action): bool { $this->actions[] = $action; return true; }
}
