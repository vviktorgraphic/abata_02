<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingCreateOutcome;
use App\Application\Booking\BookingCreateWorkflow;
use App\Application\Booking\BookingOutboxDispatcher;
use App\Application\Booking\DefaultBookingCreateWorkflow;
use App\Application\Mail\BookingRequestMailRenderer;
use App\Application\Mail\BookingRequestOutboxDispatcher;
use App\Application\Mail\InMemoryMailer;
use App\Application\Booking\IdempotencyConflict;
use App\Application\Pricing\PricingConfigurationException;
use App\Domain\Booking\BookingCreateRequest;
use App\Domain\Booking\BookingCreateRequestValidator;
use App\Http\BookingRequestRateLimiter;
use App\Http\Controller\BookingCreateController;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingRequestOutbox;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BookingCreateApiTest extends TestCase
{
    public function testValidBookingReturns201AndPublicContract(): void
    {
        $controller = $this->controller($this->workflow(new BookingCreateOutcome(
            'AB-TEST-123', 'pending', '36000.00', 'HUF', 'sent', false,
        )));

        $response = $controller->create($this->json(), ['Content-Type' => 'application/json'], '192.0.2.10');

        self::assertSame(201, $response->status);
        self::assertSame('pending', $response->payload['status']);
        self::assertSame('AB-TEST-123', $response->payload['reference']);
        self::assertSame('36000.00', $response->payload['total_amount']);
        self::assertSame('HUF', $response->payload['currency']);
        self::assertSame('sent', $response->payload['email_status']);
        self::assertArrayNotHasKey('booking_id', $response->payload);
        self::assertStringNotContainsString('guest@example.test', json_encode($response->payload, JSON_THROW_ON_ERROR));
    }

    public function testIdempotentReplayReturns200WithSameReference(): void
    {
        $response = $this->controller($this->workflow(new BookingCreateOutcome(
            'AB-SAME', 'pending', '36000.00', 'HUF', 'sent', true,
        )))->create($this->json(), ['content-type' => 'application/json; charset=utf-8'], '192.0.2.10');

        self::assertSame(200, $response->status);
        self::assertSame('AB-SAME', $response->payload['reference']);
    }

    public function testIntegratedApiPersistsOneBookingSnapshotAndOutboxOnReplay(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $pdo = ConnectionFactory::create(require dirname(__DIR__, 2) . '/config/database.php');
        $priceName = 'API integration ' . bin2hex(random_bytes(6));
        $rule = $pdo->prepare('INSERT INTO pricing_rules (name, rule_type, valid_from, valid_until, nightly_price, amount, adjustment_mode, base_unit, currency, minimum_nights, priority, is_active) VALUES (:name, \'base\', :from, :until, :price, :amount, \'fixed\', \'per_person_per_night\', \'HUF\', 1, 9999, 1)');
        $rule->execute(['name' => $priceName, 'from' => '2039-01-01', 'until' => '2041-01-01', 'price' => '1000.00', 'amount' => '1000.00']);
        $ruleId = (int) $pdo->lastInsertId();
        $mailer = new InMemoryMailer();
        $workflow = new DefaultBookingCreateWorkflow(
            new TransactionalBookingRepository($pdo),
            new PdoPricingEngineAdapter($pdo),
            new BookingOutboxDispatcher(new BookingRequestOutboxDispatcher(
                new PdoBookingRequestOutbox($pdo),
                new BookingRequestMailRenderer(dirname(__DIR__, 2) . '/templates/email', 'no-reply@example.test'),
                $mailer,
            )),
            new \App\Application\Booking\BudapestBookingClock(),
            '/booking-policy',
            'test-v1',
            '/adatkezelesi_tajekoztato',
            'privacy-test-v1',
        );
        $controller = new BookingCreateController(
            new BookingCreateRequestValidator(new DateTimeImmutable('2040-01-01', new DateTimeZone('Europe/Budapest'))),
            $workflow,
            new class implements BookingRequestRateLimiter { public function allow(string $clientAddress): bool { return true; } },
            [],
        );
        $payload = $this->payload();
        $payload['arrival_date'] = '2040-08-10';
        $payload['departure_date'] = '2040-08-13';
        $payload['idempotency_key'] = 'api-integrated-' . bin2hex(random_bytes(10));

        try {
            $first = $controller->create(json_encode($payload, JSON_THROW_ON_ERROR), ['content-type' => 'application/json'], '192.0.2.20');
            $second = $controller->create(json_encode($payload, JSON_THROW_ON_ERROR), ['content-type' => 'application/json'], '192.0.2.20');
            self::assertSame(201, $first->status);
            self::assertSame(200, $second->status);
            self::assertSame($first->payload['reference'], $second->payload['reference']);
            $booking = $pdo->prepare('SELECT id FROM bookings WHERE reference = :reference');
            $booking->execute(['reference' => $first->payload['reference']]);
            $bookingId = (int) $booking->fetchColumn();
            self::assertGreaterThan(0, $bookingId);
            foreach (['booking_pricing_snapshots', 'email_outbox', 'booking_status_history', 'booking_idempotency'] as $table) {
                $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_id = :id");
                $count->execute(['id' => $bookingId]);
                self::assertSame(1, (int) $count->fetchColumn(), $table);
            }
            self::assertCount(1, $mailer->messages());
        } finally {
            if (isset($bookingId) && $bookingId > 0) $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute(['id' => $bookingId]);
            $pdo->prepare('DELETE FROM pricing_rules WHERE id = :id')->execute(['id' => $ruleId]);
        }
    }

    #[DataProvider('badTransportProvider')]
    public function testTransportLevelValidation(string $body, array $headers, int $expectedStatus): void
    {
        $response = $this->controller($this->workflow())->create($body, $headers, '192.0.2.10');
        self::assertSame($expectedStatus, $response->status);
        self::assertArrayHasKey('error', $response->payload);
    }

    /** @return iterable<string, array{string, array<string, string>, int}> */
    public static function badTransportProvider(): iterable
    {
        yield 'wrong content type' => ['{}', ['content-type' => 'text/plain'], 415];
        yield 'invalid json' => ['{broken', ['content-type' => 'application/json'], 400];
        yield 'list body' => ['[]', ['content-type' => 'application/json'], 422];
        yield 'oversized body' => [str_repeat('x', 1025), ['content-type' => 'application/json'], 413];
        yield 'oversized declared body' => ['{}', ['content-type' => 'application/json', 'content-length' => '1025'], 413];
        yield 'foreign origin' => ['{}', ['content-type' => 'application/json', 'origin' => 'https://evil.example'], 403];
    }

    public function testTrustedOriginAndRefererAreAccepted(): void
    {
        foreach (['origin' => 'https://booking.example', 'referer' => 'https://booking.example/form'] as $name => $value) {
            $response = $this->controller($this->workflow())->create(
                $this->json(), ['content-type' => 'application/json', $name => $value], '192.0.2.10',
            );
            self::assertSame(201, $response->status);
        }
    }

    public function testDomainValidationErrorsStayFieldScoped(): void
    {
        $payload = $this->payload();
        $payload['privacy_accepted'] = false;
        $payload['children'] = 2;
        $response = $this->controller($this->workflow())->create(
            json_encode($payload, JSON_THROW_ON_ERROR), ['content-type' => 'application/json'], '192.0.2.10',
        );
        self::assertSame(422, $response->status);
        self::assertArrayHasKey('privacy_accepted', $response->payload['errors']);
        self::assertArrayHasKey('child_ages', $response->payload['errors']);
    }

    public function testHoneypotIsRejectedWithoutCallingWorkflow(): void
    {
        $payload = $this->payload();
        $payload['website'] = 'spam.example';
        $response = $this->controller($this->workflow())->create(
            json_encode($payload, JSON_THROW_ON_ERROR), ['content-type' => 'application/json'], '192.0.2.10',
        );
        self::assertSame(422, $response->status);
        self::assertArrayHasKey('website', $response->payload['errors']);
    }

    public function testRateLimitReturns429BeforeProcessingBody(): void
    {
        $limiter = new class implements BookingRequestRateLimiter {
            public function allow(string $clientAddress): bool { return false; }
        };
        $response = $this->controller($this->workflow(), $limiter)->create(
            $this->json(), ['content-type' => 'application/json'], '192.0.2.10',
        );
        self::assertSame(429, $response->status);
        self::assertSame('60', $response->headers['Retry-After']);
    }

    public function testPricingConfigurationFailureWritesPiiFreeAuditEvent(): void
    {
        $audit = new class implements AuditLog {
            /** @var list<AuditEvent> */ public array $events = [];
            public function append(AuditEvent $event): void { $this->events[] = $event; }
        };
        $workflow = new class implements BookingCreateWorkflow {
            public function create(BookingCreateRequest $request): BookingCreateOutcome
            {
                throw new PricingConfigurationException('guest@example.test secret-host');
            }
        };
        $limiter = new class implements BookingRequestRateLimiter { public function allow(string $clientAddress): bool { return true; } };
        $controller = new BookingCreateController(
            new BookingCreateRequestValidator(new DateTimeImmutable('2026-01-01', new DateTimeZone('Europe/Budapest'))),
            $workflow, $limiter, [], 1024, $audit,
        );

        $response = $controller->create($this->json(), ['content-type' => 'application/json'], '192.0.2.10');

        self::assertSame(503, $response->status);
        self::assertCount(1, $audit->events);
        self::assertSame('booking.pricing_unavailable', $audit->events[0]->eventType);
        $serialized = json_encode($audit->events[0]->metadata->values, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('guest@example.test', $serialized);
        self::assertStringNotContainsString('secret-host', $serialized);
    }

    #[DataProvider('workflowErrorProvider')]
    public function testWorkflowErrorsAreSafe(\Throwable $error, int $status): void
    {
        $workflow = new class($error) implements BookingCreateWorkflow {
            public function __construct(private readonly \Throwable $error) {}
            public function create(BookingCreateRequest $request): BookingCreateOutcome { throw $this->error; }
        };
        $response = $this->controller($workflow)->create(
            $this->json(), ['content-type' => 'application/json'], '192.0.2.10',
        );
        self::assertSame($status, $response->status);
        $json = json_encode($response->payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-host', $json);
        self::assertStringNotContainsString('guest@example.test', $json);
        self::assertStringNotContainsString('trace', strtolower($json));
    }

    /** @return iterable<string, array{\Throwable, int}> */
    public static function workflowErrorProvider(): iterable
    {
        yield 'confirmed or blocked conflict' => [new BookingConflict('secret-host guest@example.test'), 409];
        yield 'changed idempotent payload' => [new IdempotencyConflict('secret-host'), 409];
        yield 'missing price' => [new PricingConfigurationException('secret-host'), 503];
        yield 'unexpected failure' => [new RuntimeException('secret-host guest@example.test'), 503];
    }

    private function controller(BookingCreateWorkflow $workflow, ?BookingRequestRateLimiter $limiter = null): BookingCreateController
    {
        $limiter ??= new class implements BookingRequestRateLimiter {
            public function allow(string $clientAddress): bool { return true; }
        };
        return new BookingCreateController(
            new BookingCreateRequestValidator(new DateTimeImmutable('2026-01-01', new DateTimeZone('Europe/Budapest'))),
            $workflow,
            $limiter,
            ['https://booking.example'],
            1024,
        );
    }

    private function workflow(?BookingCreateOutcome $outcome = null): BookingCreateWorkflow
    {
        $outcome ??= new BookingCreateOutcome('AB-TEST', 'pending', '36000.00', 'HUF', 'pending', false);
        return new class($outcome) implements BookingCreateWorkflow {
            public function __construct(private readonly BookingCreateOutcome $outcome) {}
            public function create(BookingCreateRequest $request): BookingCreateOutcome { return $this->outcome; }
        };
    }

    private function json(): string
    {
        return json_encode($this->payload(), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'arrival_date' => '2026-08-10', 'departure_date' => '2026-08-13',
            'contact_name' => 'Teszt Elek', 'email' => 'guest@example.test',
            'phone' => '+3612345678', 'adults' => 2, 'children' => 1,
            'child_ages' => [6], 'notes' => '', 'privacy_accepted' => true,
            'booking_policy_accepted' => true,
            'idempotency_key' => 'client-generated-value-123', 'website' => '',
        ];
    }
}
