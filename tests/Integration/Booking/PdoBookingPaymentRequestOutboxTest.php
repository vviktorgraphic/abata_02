<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\BookingPaymentRequestDispatcher;
use App\Application\Mail\BookingPaymentRequestMailRenderer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingPaymentRequestOutbox;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoBookingPaymentRequestOutboxTest extends TestCase
{
    private PDO $pdo;
    private int $bookingId;
    private string $reference;
    private ?int $adminId = null;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) { self::markTestSkipped('Database environment is not configured.'); }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $this->reference = 'PAY-' . strtoupper(bin2hex(random_bytes(6)));
        $query = $this->pdo->prepare("INSERT INTO bookings (reference, status, arrival_date, departure_date, guest_name, guest_email, total_amount)
            VALUES (:reference, 'pending', '2040-01-01', '2040-01-04', 'Test guest', 'test@example.invalid', 126001.00)");
        $query->execute(['reference' => $this->reference]);
        $this->bookingId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->bookingId)) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type = 'booking' AND target_id = :id")
                ->execute(['id' => (string) $this->bookingId]);
            $query = $this->pdo->prepare('DELETE FROM bookings WHERE id = :id');
            $query->execute(['id' => $this->bookingId]);
            if ($this->adminId !== null) {
                $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $this->adminId]);
            }
        }
    }

    public function testAtomicClaimRetryUsesFrozenPayloadAndSentCannotBeClaimed(): void
    {
        $outbox = new PdoBookingPaymentRequestOutbox($this->pdo);
        $item = $outbox->claim($this->reference, $this->config());
        self::assertNotNull($item);
        self::assertFalse($this->pdo->inTransaction());
        self::assertFalse($item['retry']);
        self::assertSame('63001.00', $item['data']->advanceAmount);
        $other = new PdoBookingPaymentRequestOutbox(ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php'));
        self::assertNull($other->claim($this->reference, $this->config()));
        $outbox->markFailed($item['id'], 'E-mail transport failure.');
        $query = $this->pdo->prepare('UPDATE bookings SET total_amount = 999999.00 WHERE id = :id');
        $query->execute(['id' => $this->bookingId]);
        $retry = $outbox->claim($this->reference, new BookingPaymentRequestConfiguration('', '', 0));
        self::assertTrue($retry['retry']);
        self::assertSame($item['id'], $retry['id']);
        self::assertEquals($item['data'], $retry['data']);
        $outbox->markSent($retry['id']);
        self::assertNull($outbox->claim($this->reference, $this->config()));
        self::assertSame('sent', $outbox->status($this->reference));
        self::assertSame('pending', $this->bookingStatus());
        $query = $this->pdo->prepare('SELECT attempts, sent_at FROM email_outbox WHERE booking_id = :id');
        $query->execute(['id' => $this->bookingId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $row['attempts']);
        self::assertNotNull($row['sent_at']);
        self::assertFalse($query->fetch());
    }

    public function testOnlyPendingCanSendOrRetry(): void
    {
        $outbox = new PdoBookingPaymentRequestOutbox($this->pdo);
        $item = $outbox->claim($this->reference, $this->config());
        $outbox->markFailed($item['id'], 'E-mail transport failure.');
        foreach (['confirmed', 'rejected', 'invalidated', 'cancelled'] as $status) {
            $query = $this->pdo->prepare('UPDATE bookings SET status = :status WHERE id = :id');
            $query->execute(['id' => $this->bookingId, 'status' => $status]);
            try { $outbox->claim($this->reference, $this->config()); self::fail('Non-pending booking accepted.'); }
            catch (\DomainException) { self::assertSame('failed', $outbox->status($this->reference)); }
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testMissingConfigurationQueuesNothingAndNeverCallsSmtp(): void
    {
        $mailer = new class implements Mailer {
            public int $calls = 0;
            public function send(Message $message): void { ++$this->calls; }
        };
        $dispatcher = new BookingPaymentRequestDispatcher(new PdoBookingPaymentRequestOutbox($this->pdo),
            $this->renderer(), $mailer, new BookingPaymentRequestConfiguration('', ''));
        try { $dispatcher->dispatch($this->reference); self::fail('Missing configuration accepted.'); }
        catch (\InvalidArgumentException) { self::assertSame(0, $mailer->calls); }
        self::assertSame('pending', $this->bookingStatus());
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM email_outbox WHERE booking_id = :id');
        $query->execute(['id' => $this->bookingId]);
        self::assertSame(0, (int) $query->fetchColumn());
    }

    public function testSmtpIsAfterCommitAndFailurePreservesPending(): void
    {
        $mailer = new class($this->pdo) implements Mailer {
            public ?bool $insideTransaction = null;
            public function __construct(private PDO $pdo) {}
            public function send(Message $message): void
            {
                $this->insideTransaction = $this->pdo->inTransaction();
                throw new \RuntimeException('private transport detail');
            }
        };
        $outbox = new PdoBookingPaymentRequestOutbox($this->pdo);
        $dispatcher = new BookingPaymentRequestDispatcher($outbox, $this->renderer(), $mailer, $this->config());
        self::assertSame('failed', $dispatcher->dispatch($this->reference)->status);
        self::assertFalse($mailer->insideTransaction);
        self::assertSame('pending', $this->bookingStatus());
        self::assertSame('failed', $outbox->status($this->reference));
    }

    public function testDirectConfirmPostRequiresSentRequestAndStillSendsConfirmation(): void
    {
        $this->pdo->prepare("INSERT INTO admins (email,password_hash,name) VALUES (:email,'test-only','Payment test')")
            ->execute(['email' => $this->reference . '@example.invalid']);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $auth = $this->createStub(\App\Http\Controller\Admin\AdminAuthWorkflow::class);
        $auth->method('currentAdmin')->willReturn(['id' => $this->adminId, 'name' => 'Test']);
        $session = $this->createStub(\App\Security\Session\SessionStorage::class);
        $session->method('get')->willReturn('test-csrf');
        $csrf = new \App\Security\Csrf\CsrfTokenManager($session);
        $limiter = $this->createStub(\App\Http\Controller\Admin\AdminActionRateLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $mailer = new \App\Application\Mail\InMemoryMailer();
        $audit = new \App\Infrastructure\Persistence\Auth\PdoAuditLog($this->pdo);
        $payments = new BookingPaymentRequestDispatcher(new PdoBookingPaymentRequestOutbox($this->pdo), $this->renderer(), $mailer, $this->config(), $audit);
        $notifications = new \App\Application\Mail\BookingStatusNotificationDispatcher(
            new \App\Infrastructure\Persistence\Booking\PdoBookingStatusNotificationOutbox($this->pdo),
            new \App\Application\Mail\BookingStatusMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'test@example.invalid'),
            $mailer, $audit,
        );
        $controller = new \App\Http\Controller\Admin\BookingManagementController(
            $auth, new \App\Http\Controller\Admin\AdminView(dirname(__DIR__, 3) . '/templates'), $csrf,
            new \App\Http\Controller\Admin\AdminActionGuard($auth, $csrf, $limiter),
            new \App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository($this->pdo),
            new \App\Infrastructure\Persistence\Booking\TransactionalBookingRepository($this->pdo), $notifications, $payments, $this->config(),
        );
        $form = ['_csrf' => 'test-csrf'];
        self::assertSame(403, $controller->paymentRequest($this->reference, [], 'application/x-www-form-urlencoded', 100)->status);
        self::assertSame(409, $controller->transition($this->reference, 'confirm', $form, 'application/x-www-form-urlencoded', 100)->status);
        self::assertSame('pending', $this->bookingStatus());
        self::assertCount(0, $mailer->messages());
        $controller->paymentRequest($this->reference, $form, 'application/x-www-form-urlencoded', 100);
        self::assertSame('pending', $this->bookingStatus());
        $response = $controller->transition($this->reference, 'confirm', $form, 'application/x-www-form-urlencoded', 100);
        self::assertInstanceOf(\App\Http\Controller\Admin\RedirectResponse::class, $response);
        self::assertSame('confirmed', $this->bookingStatus());
        self::assertCount(2, $mailer->messages());
        $query = $this->pdo->prepare('SELECT message_type,status FROM email_outbox WHERE booking_id = :id ORDER BY id');
        $query->execute(['id' => $this->bookingId]);
        self::assertSame(['booking_payment_request' => 'sent', 'booking_confirmed' => 'sent'], $query->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function testCannotRunInsideExistingTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->expectException(\LogicException::class);
        (new PdoBookingPaymentRequestOutbox($this->pdo))->claim($this->reference, $this->config());
    }

    public function testMissingHistoricalPriceCannotSendAnInventedZeroAdvance(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE legacy_booking_imports (booking_id BIGINT PRIMARY KEY, pricing_unavailable BOOLEAN NOT NULL)');
        try {
            $this->pdo->prepare('INSERT INTO legacy_booking_imports VALUES (:id, 1)')->execute(['id' => $this->bookingId]);
            try {
                (new PdoBookingPaymentRequestOutbox($this->pdo))->claim($this->reference, $this->config());
                self::fail('Missing historical price must block a new payment request.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('tárolt ára hiányzik', $error->getMessage());
                self::assertSame('pending', $this->bookingStatus());
                $query = $this->pdo->prepare('SELECT COUNT(*) FROM email_outbox WHERE booking_id = :id');
                $query->execute(['id' => $this->bookingId]);
                self::assertSame(0, (int) $query->fetchColumn());
            }
        } finally {
            $this->pdo->exec('DROP TEMPORARY TABLE legacy_booking_imports');
        }
    }

    private function config(): BookingPaymentRequestConfiguration
    {
        return new BookingPaymentRequestConfiguration('Test beneficiary', 'TEST-ACCOUNT');
    }

    private function renderer(): BookingPaymentRequestMailRenderer
    {
        return new BookingPaymentRequestMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'test@example.invalid');
    }

    private function bookingStatus(): string
    {
        $query = $this->pdo->prepare('SELECT status FROM bookings WHERE id = :id');
        $query->execute(['id' => $this->bookingId]);
        return $query->fetchColumn();
    }
}
