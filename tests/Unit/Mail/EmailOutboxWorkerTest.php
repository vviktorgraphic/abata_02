<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Mail\BookingManualCommunicationRenderer;
use App\Application\Mail\BookingModificationMailRenderer;
use App\Application\Mail\BookingPaymentRequestMailRenderer;
use App\Application\Mail\BookingRequestMailRenderer;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Application\Mail\BookingStatusMailRenderer;
use App\Application\Mail\EmailOutboxMessageFactory;
use App\Application\Mail\EmailOutboxRecoveredItem;
use App\Application\Mail\EmailOutboxWorker;
use App\Application\Mail\EmailOutboxWorkerRepository;
use App\Application\Mail\EmailOutboxWorkItem;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EmailOutboxWorkerTest extends TestCase
{
    public function testEveryStoredBookingMessageTypeUsesItsExistingRendererAndIsSentOnce(): void
    {
        $items = [];
        foreach ($this->payloads() as $type => [$recipient, $payload]) {
            $items[] = new EmailOutboxWorkItem(count($items) + 1, 100 + count($items), $type, $recipient, 'Stored subject', json_encode($payload, JSON_THROW_ON_ERROR), 0);
        }
        $repository = new FakeEmailOutboxWorkerRepository($items);
        $mailer = new InMemoryMailer();

        $result = (new EmailOutboxWorker($repository, $this->factory(), $mailer, clock: $this->clock()))->run();

        self::assertSame(10, $result['claimed']);
        self::assertSame(10, $result['sent']);
        self::assertSame(0, $result['failed']);
        self::assertCount(10, $mailer->messages());
        self::assertCount(10, $repository->sent);
        self::assertTrue($repository->released);
        self::assertNotContains('admin_two_factor', $this->factory()->supportedTypes());
    }

    public function testMalformedPayloadIsFinalFailedWithoutSmtpAndBatchContinues(): void
    {
        $valid = $this->item(2, 'booking_review_request', 'guest@example.test', ['recipient' => 'guest@example.test']);
        $repository = new FakeEmailOutboxWorkerRepository([
            new EmailOutboxWorkItem(1, 11, 'booking_confirmed', 'guest@example.test', 'Subject', '{bad json', 1),
            $valid,
        ]);
        $mailer = new InMemoryMailer();
        $audit = new FakeEmailOutboxAuditLog();

        $result = (new EmailOutboxWorker($repository, $this->factory(), $mailer, $audit, $this->clock()))->run();

        self::assertSame(2, $result['claimed']);
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['final_failed']);
        self::assertSame([1], $repository->payloadFailed);
        self::assertCount(1, $mailer->messages());
        self::assertSame('email.outbox_payload_failed', $audit->events[0]->eventType);
        self::assertArrayNotHasKey('recipient', $audit->events[0]->metadata->values);
    }

    public function testTransportFailureIsSafeAndDoesNotStopLaterRows(): void
    {
        $repository = new FakeEmailOutboxWorkerRepository([
            $this->item(1, 'booking_review_request', 'first@example.test', ['recipient' => 'first@example.test'], 4),
            $this->item(2, 'booking_review_request', 'second@example.test', ['recipient' => 'second@example.test']),
        ]);
        $mailer = new class implements Mailer {
            public int $calls = 0;
            public function send(Message $message): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('smtp://user:secret@example.test');
                }
            }
        };

        $result = (new EmailOutboxWorker($repository, $this->factory(), $mailer, clock: $this->clock()))->run();

        self::assertSame(2, $mailer->calls);
        self::assertSame([1], $repository->transportFailed);
        self::assertSame([2], $repository->sent);
        self::assertSame(1, $result['final_failed']);
    }

    public function testStaleRecoveryConsumesBatchCapacityAndUsesSafeSummary(): void
    {
        $recovered = [];
        for ($index = 1; $index <= EmailOutboxWorker::BATCH_LIMIT; ++$index) {
            $recovered[] = new EmailOutboxRecoveredItem($index, 100 + $index, 'booking_confirmed', $index === 1 ? 5 : 1);
        }
        $repository = new FakeEmailOutboxWorkerRepository([
            $this->item(99, 'booking_review_request', 'guest@example.test', ['recipient' => 'guest@example.test']),
        ], $recovered, 3);

        $result = (new EmailOutboxWorker($repository, $this->factory(), new InMemoryMailer(), clock: $this->clock()))->run();

        self::assertSame(25, $result['stale_recovered']);
        self::assertSame(0, $result['claimed']);
        self::assertSame(1, $result['final_failed']);
        self::assertSame(3, $result['unsupported']);
    }

    public function testHeldLockIsSuccessfulNoOp(): void
    {
        $repository = new FakeEmailOutboxWorkerRepository([], lockAvailable: false);

        $result = (new EmailOutboxWorker($repository, $this->factory(), new InMemoryMailer(), clock: $this->clock()))->run();

        self::assertSame(['stale_recovered'=>0,'claimed'=>0,'sent'=>0,'failed'=>0,'final_failed'=>0,'unsupported'=>0], $result);
        self::assertFalse($repository->released);
    }

    public function testLockIsReleasedOnFatalFailure(): void
    {
        $repository = new FakeEmailOutboxWorkerRepository([], fatalOnClaim: true);

        try {
            (new EmailOutboxWorker($repository, $this->factory(), new InMemoryMailer(), clock: $this->clock()))->run();
            self::fail('Fatal repository failure expected.');
        } catch (\RuntimeException) {
            self::assertTrue($repository->released);
        }
    }

    private function factory(): EmailOutboxMessageFactory
    {
        $templates = dirname(__DIR__, 3) . '/templates/email';
        $common = [$templates, 'noreply@example.test', 'A Bata', 'info@example.test', 'A Bata'];

        return new EmailOutboxMessageFactory(
            new BookingRequestMailRenderer(...$common),
            new BookingStatusMailRenderer(...$common),
            new BookingPaymentRequestMailRenderer(...$common),
            new BookingManualCommunicationRenderer($templates, dirname(__DIR__, 3) . '/resources/email/arrival', 'noreply@example.test', 'A Bata', 'info@example.test', 'A Bata'),
            new BookingModificationMailRenderer(...$common),
            new BookingReviewMailRenderer(...$common),
        );
    }

    /** @return \Closure():DateTimeImmutable */
    private function clock(): \Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-12 12:00:00', new DateTimeZone('Europe/Budapest'));
    }

    /** @param array<string,mixed> $payload */
    private function item(int $id, string $type, string $recipient, array $payload, int $attempts = 0): EmailOutboxWorkItem
    {
        return new EmailOutboxWorkItem($id, 100 + $id, $type, $recipient, 'Subject', json_encode($payload, JSON_THROW_ON_ERROR), $attempts);
    }

    /** @return array<string, array{string, array<string,mixed>}> */
    private function payloads(): array
    {
        $request = ['booking_reference'=>'AB-1','arrival_date'=>'2026-11-01','departure_date'=>'2026-11-03','adults'=>2,'child_ages'=>[7],'total'=>'50000.00','currency'=>'HUF','contact_name'=>'Test Guest','guest_email'=>'guest@example.test','phone'=>'+360000000'];
        $status = ['booking_reference'=>'AB-1','arrival_date'=>'2026-11-01','departure_date'=>'2026-11-03','adults'=>2,'children'=>1,'total'=>'50000.00','currency'=>'HUF','contact_name'=>'Test Guest'];
        $payment = ['booking_reference'=>'AB-1','recipient'=>'guest@example.test','contact_name'=>'Test Guest','arrival_date'=>'2026-11-01','departure_date'=>'2026-11-03','currency'=>'HUF','total'=>'50000.00','advance_percent'=>50,'advance_amount'=>'24000.00','beneficiary'=>'Test Owner','bank_account'=>'TEST-ACCOUNT','accommodation_fee'=>'48000.00','taxes'=>'2000.00','payment_reference'=>'AB-1','bank_name'=>'Test Bank','swift_bic'=>'TESTHUHB','template_version'=>2];
        $reminder = ['recipient'=>'guest@example.test','contact_name'=>'Test Guest','advance_amount'=>'24000.00','payment_reference'=>'AB-1','beneficiary'=>'Test Owner','bank_account'=>'TEST-ACCOUNT','bank_name'=>'Test Bank','swift_bic'=>'TESTHUHB','template_version'=>1];
        $arrival = ['recipient'=>'guest@example.test','contact_name'=>'Test Guest','template_version'=>1];
        $modified = ['contact_name'=>'Test Guest','booking_reference'=>'AB-1','arrival_date'=>'2026-11-02','departure_date'=>'2026-11-04','nights'=>2,'adults'=>2,'child_ages'=>[7],'accommodation_fee'=>'48000.00','tourism_tax'=>'2000.00','total_amount'=>'50000.00','unchanged_deposit_amount'=>'24000.00','currency'=>'HUF'];

        return [
            'booking_request_received' => ['guest@example.test', $request],
            'booking_request_admin_notification' => ['owner@example.test', $request],
            'booking_confirmed' => ['guest@example.test', $status],
            'booking_rejected' => ['guest@example.test', $status],
            'booking_cancelled' => ['guest@example.test', $status + ['cancellation_penalty_amount'=>'0.00','cancellation_accommodation_fee'=>'48000.00']],
            'booking_payment_request' => ['guest@example.test', $payment],
            'booking_payment_reminder' => ['guest@example.test', $reminder],
            'booking_arrival_information' => ['guest@example.test', $arrival],
            'booking_modified' => ['guest@example.test', $modified],
            'booking_review_request' => ['guest@example.test', ['recipient'=>'guest@example.test','template_version'=>1]],
        ];
    }
}

final class FakeEmailOutboxWorkerRepository implements EmailOutboxWorkerRepository
{
    /** @var list<EmailOutboxWorkItem> */
    private array $items;
    /** @var list<int> */
    public array $sent = [];
    /** @var list<int> */
    public array $transportFailed = [];
    /** @var list<int> */
    public array $payloadFailed = [];
    public bool $released = false;

    /** @param list<EmailOutboxWorkItem> $items @param list<EmailOutboxRecoveredItem> $recovered */
    public function __construct(
        array $items,
        private array $recovered = [],
        private int $unsupported = 0,
        private bool $lockAvailable = true,
        private bool $fatalOnClaim = false,
    ) { $this->items = $items; }

    public function acquireWorkerLock(): bool { return $this->lockAvailable; }
    public function releaseWorkerLock(): void { $this->released = true; }
    public function recoverStale(DateTimeImmutable $now, array $supportedTypes, int $limit): array { return array_slice($this->recovered, 0, $limit); }
    public function claimNextDue(DateTimeImmutable $now, array $supportedTypes): ?EmailOutboxWorkItem
    {
        if ($this->fatalOnClaim) throw new \RuntimeException('DB unavailable');
        return array_shift($this->items);
    }
    public function markSent(EmailOutboxWorkItem $item, DateTimeImmutable $now): bool { $this->sent[]=$item->id; return true; }
    public function markTransportFailed(EmailOutboxWorkItem $item, DateTimeImmutable $now): bool { $this->transportFailed[]=$item->id; return true; }
    public function markPayloadFailed(EmailOutboxWorkItem $item, DateTimeImmutable $now): bool { $this->payloadFailed[]=$item->id; return true; }
    public function countUnsupported(array $supportedTypes): int { return $this->unsupported; }
}

final class FakeEmailOutboxAuditLog implements AuditLog
{
    /** @var list<AuditEvent> */
    public array $events = [];
    public function append(AuditEvent $event): void { $this->events[] = $event; }
}
