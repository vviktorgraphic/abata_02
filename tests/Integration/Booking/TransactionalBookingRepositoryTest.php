<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingCreationOptions;
use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPricing;
use App\Application\Booking\BookingPricingProvider;
use App\Application\Booking\BookingPreviewStale;
use App\Application\Booking\IdempotencyConflict;
use App\Application\Booking\AdminBookingListQuery;
use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Application\Booking\AdminCustomPrice;
use App\Application\Booking\AdminPricingOverride;
use App\Application\Booking\PricingSnapshotHasher;
use App\Application\Mail\BookingNotificationRecipientProvider;
use App\Application\Mail\BookingNotificationRecipientResolver;
use App\Domain\Booking\AvailabilityService;
use App\Domain\Booking\BookingPeriod;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\PdoAdminDashboardRepository;
use App\Infrastructure\Persistence\Booking\PdoAdminMonthlyOccupancyRepository;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarExportFeedRepository;
use App\Infrastructure\Persistence\PdoBookingReadRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class TransactionalBookingRepositoryTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $bookingIds = [];
    /** @var list<int> */
    private array $blockedIds = [];
    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->bookingIds as $id) {
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type = 'booking' AND target_id = :id")
                ->execute(['id' => (string) $id]);
            $statement = $this->pdo->prepare('DELETE FROM bookings WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
        foreach ($this->blockedIds as $id) {
            $statement = $this->pdo->prepare('DELETE FROM blocked_periods WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
        foreach ($this->adminIds as $id) {
            $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $id]);
        }
    }

    public function testAdminManualCreationPersistsPendingWithoutLegalOrEmailSideEffectsAndFeedsReadModels(): void
    {
        $adminId = $this->insertAdmin();
        $repository = new TransactionalBookingRepository($this->pdo);
        $reference = 'ADMIN-MANUAL-' . strtoupper(bin2hex(random_bytes(5)));
        $command = $this->adminCommand($reference, '2097-02-10', '2097-02-13');
        $options = $this->adminOptions($adminId, $command);
        $baselinePending = (new PdoAdminDashboardRepository($this->pdo))->fetch(
            new DateTimeImmutable('2097-02-01', new DateTimeZone('Europe/Budapest')),
        )->pendingCount;

        $created = $repository->create($command, $this->pricing(), $options);
        $this->bookingIds[] = $created->bookingId;
        $replayed = $repository->create($command, $this->pricing(), $options);

        self::assertSame('pending', $created->status);
        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->bookingId, $replayed->bookingId);

        $booking = $this->pdo->prepare(
            'SELECT status, privacy_accepted_at, privacy_policy_version, privacy_policy_url,
                    booking_policy_accepted_at, booking_policy_version, booking_policy_url,
                    house_rules_accepted_at, house_rules_url
             FROM bookings WHERE id = :id'
        );
        $booking->execute(['id' => $created->bookingId]);
        $row = $booking->fetch(PDO::FETCH_ASSOC);
        self::assertSame('pending', $row['status']);
        foreach (array_diff(array_keys($row), ['status']) as $field) {
            self::assertNull($row[$field], $field . ' must remain null for an admin-created booking.');
        }
        self::assertSame(2, $this->countBookingRows('booking_child_ages', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_pricing_snapshots', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_idempotency', $created->bookingId));
        self::assertSame(0, $this->countBookingRows('email_outbox', $created->bookingId));

        $history = $this->pdo->prepare('SELECT old_status, new_status, changed_by_admin_id, note FROM booking_status_history WHERE booking_id = :id');
        $history->execute(['id' => $created->bookingId]);
        self::assertSame([
            'old_status' => null,
            'new_status' => 'pending',
            'changed_by_admin_id' => $adminId,
            'note' => 'Admin által rögzített foglalás',
        ], $history->fetch(PDO::FETCH_ASSOC));

        $audit = $this->pdo->prepare('SELECT event_type, admin_id, outcome, metadata_json FROM audit_logs WHERE target_type = \'booking\' AND target_id = :id');
        $audit->execute(['id' => (string) $created->bookingId]);
        $audits = $audit->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $audits);
        self::assertSame('booking.admin_created', $audits[0]['event_type']);
        self::assertSame($adminId, $audits[0]['admin_id']);
        self::assertSame('success', $audits[0]['outcome']);
        $metadata = json_decode($audits[0]['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(['booking_reference', 'status', 'source'], array_keys($metadata));
        self::assertSame($reference, $metadata['booking_reference']);
        self::assertSame('pending', $metadata['status']);
        self::assertSame('admin', $metadata['source']);

        $queryRepository = new PdoAdminBookingQueryRepository($this->pdo);
        $list = $queryRepository->fetchBookingList(new AdminBookingListQuery(['search' => $reference]));
        self::assertSame([$reference], array_column($list, 'reference'));
        self::assertSame($reference, $queryRepository->fetchBookingDetail(new \App\Application\Booking\AdminBookingDetailQuery($reference))['reference']);

        $dashboard = (new PdoAdminDashboardRepository($this->pdo))->fetch(
            new DateTimeImmutable('2097-02-01', new DateTimeZone('Europe/Budapest')),
        );
        self::assertSame($baselinePending + 1, $dashboard->pendingCount);

        $monthly = (new PdoAdminMonthlyOccupancyRepository($this->pdo))->fetch(new AdminMonthlyOccupancyQuery(
            '2097-02',
            new DateTimeImmutable('2097-02-01', new DateTimeZone('Europe/Budapest')),
        ));
        $monthlyReferences = [];
        foreach ($monthly['days'] as $day) {
            foreach ($day['bookings'] as $monthlyBooking) $monthlyReferences[] = $monthlyBooking['reference'];
        }
        self::assertContains($reference, $monthlyReferences);

        $zone = new DateTimeZone('Europe/Budapest');
        $requested = new BookingPeriod(new DateTimeImmutable('2097-02-11', $zone), new DateTimeImmutable('2097-02-12', $zone));
        $occupied = (new PdoBookingReadRepository($this->pdo))->findBlockingBetween($requested->arrival, $requested->departure);
        self::assertFalse((new AvailabilityService(new DateTimeImmutable('2097-02-01', $zone)))->isAvailable($requested, $occupied));

        $exportedStarts = array_map(
            static fn ($event): string => $event->startDate->format('Y-m-d'),
            (new PdoCalendarExportFeedRepository($this->pdo))->exportableEvents(),
        );
        self::assertContains('2097-02-10', $exportedStarts);

        $turnoverCommand = $this->adminCommand('ADMIN-TURNOVER-' . strtoupper(bin2hex(random_bytes(5))), '2097-02-13', '2097-02-15');
        $turnover = $repository->create(
            $turnoverCommand,
            $this->pricing(),
            $this->adminOptions($adminId, $turnoverCommand),
        );
        $this->bookingIds[] = $turnover->bookingId;
        self::assertSame('pending', $turnover->status);
    }

    public function testAdminManualCustomTotalPersistsEffectiveSnapshotAndSafeAuditWithoutEmail(): void
    {
        $adminId = $this->insertAdmin();
        $command = $this->adminCommand(
            'ADMIN-CUSTOM-' . strtoupper(bin2hex(random_bytes(5))),
            '2097-07-10',
            '2097-07-12',
        );
        $calculated = new BookingPricing('30500.00', 'HUF', [
            'version' => 5,
            'arrival_date' => $command->arrivalDate,
            'departure_date' => $command->departureDate,
            'accommodation_fee' => '30000.00',
            'taxes' => '500.00',
            'total' => '30500.00',
            'currency' => 'HUF',
            'line_items' => [],
        ]);
        $provider = new class($calculated) implements BookingPricingProvider {
            public function __construct(private BookingPricing $pricing) {}
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing { return $this->pricing; }
        };
        $custom = AdminCustomPrice::fromCanonical('28500.00');
        $effective = (new AdminPricingOverride())->applyToBookingPricing($calculated, $custom);
        $options = BookingCreationOptions::adminManual(
            $adminId,
            $calculated->totalAmount,
            PricingSnapshotHasher::hash($effective->snapshot),
            $custom->total,
            $effective->totalAmount,
            'HUF',
        );

        $created = (new TransactionalBookingRepository($this->pdo))->create($command, $provider, $options);
        $this->bookingIds[] = $created->bookingId;

        self::assertSame('28500.00', $created->totalAmount);
        $storedTotal = $this->pdo->prepare('SELECT total_amount FROM bookings WHERE id = :id');
        $storedTotal->execute(['id' => $created->bookingId]);
        self::assertSame('28500.00', $storedTotal->fetchColumn());
        $snapshotQuery = $this->pdo->prepare('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id = :id');
        $snapshotQuery->execute(['id' => $created->bookingId]);
        $snapshot = json_decode((string) $snapshotQuery->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('28000.00', $snapshot['accommodation_fee']);
        self::assertSame('500.00', $snapshot['taxes']);
        self::assertSame('28500.00', $snapshot['total']);
        self::assertSame('30500.00', $snapshot['price_override']['calculated_total']);
        self::assertSame(0, $this->countBookingRows('email_outbox', $created->bookingId));
        $audit = $this->pdo->prepare("SELECT metadata_json FROM audit_logs WHERE event_type='booking.admin_created' AND target_id=:id");
        $audit->execute(['id' => (string) $created->bookingId]);
        $metadata = json_decode((string) $audit->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('admin_custom_total', $metadata['pricing_mode']);
        self::assertSame('30500.00', $metadata['calculated_total']);
        self::assertSame('28500.00', $metadata['custom_total']);
        self::assertArrayNotHasKey('email', $metadata);
    }

    public function testAdminManualCustomTotalRejectsChangedCalculatorSnapshotAfterPreview(): void
    {
        $adminId = $this->insertAdmin();
        $command = $this->adminCommand(
            'ADMIN-CUSTOM-STALE-' . strtoupper(bin2hex(random_bytes(5))),
            '2097-08-10',
            '2097-08-12',
        );
        $provider = new class implements BookingPricingProvider {
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
            {
                return new BookingPricing('30500.00', 'HUF', [
                    'version' => 6,
                    'accommodation_fee' => '29900.00',
                    'taxes' => '600.00',
                    'total' => '30500.00',
                    'currency' => 'HUF',
                ]);
            }
        };
        $options = BookingCreationOptions::adminManual(
            $adminId,
            '30500.00',
            str_repeat('a', 64),
            '28500.00',
            '28500.00',
            'HUF',
        );

        try {
            (new TransactionalBookingRepository($this->pdo))->create($command, $provider, $options);
            self::fail('Changed calculator/IFA snapshot was accepted.');
        } catch (BookingPreviewStale $error) {
            self::assertStringContainsString('Az ár időközben megváltozott', $error->getMessage());
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference=:reference');
        $statement->execute(['reference' => $command->reference]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testAdminManualCreationRejectsBookingAndBlockedPeriodConflictsAndStalePriceAtomically(): void
    {
        $adminId = $this->insertAdmin();
        $repository = new TransactionalBookingRepository($this->pdo);
        $winnerCommand = $this->adminCommand('ADMIN-WINNER-' . strtoupper(bin2hex(random_bytes(5))), '2097-03-10', '2097-03-13');
        $winner = $repository->create(
            $winnerCommand,
            $this->pricing(),
            $this->adminOptions($adminId, $winnerCommand),
        );
        $this->bookingIds[] = $winner->bookingId;

        try {
            $repository->create(
                $this->adminCommand('ADMIN-LOSER-' . strtoupper(bin2hex(random_bytes(5))), '2097-03-11', '2097-03-12'),
                $this->pricing(),
                $this->adminOptions($adminId, $this->adminCommand('UNUSED', '2097-03-11', '2097-03-12')),
            );
            self::fail('Overlapping admin booking was accepted.');
        } catch (BookingConflict) {
            self::assertTrue(true);
        }

        $confirmedId = $this->insertConfirmed('2097-03-20', '2097-03-23');
        $this->bookingIds[] = $confirmedId;
        try {
            $repository->create(
                $this->adminCommand('ADMIN-CONFIRMED-CONFLICT-' . strtoupper(bin2hex(random_bytes(4))), '2097-03-21', '2097-03-22'),
                $this->pricing(),
                $this->adminOptions($adminId, $this->adminCommand('UNUSED', '2097-03-21', '2097-03-22')),
            );
            self::fail('Confirmed booking overlap was accepted.');
        } catch (BookingConflict) {
            self::assertTrue(true);
        }

        $blocked = $this->pdo->prepare('INSERT INTO blocked_periods (start_date, end_date, reason) VALUES (:start, :end, :reason)');
        $blocked->execute(['start' => '2097-04-10', 'end' => '2097-04-13', 'reason' => 'Admin conflict test']);
        $this->blockedIds[] = (int) $this->pdo->lastInsertId();
        try {
            $repository->create(
                $this->adminCommand('ADMIN-BLOCKED-' . strtoupper(bin2hex(random_bytes(5))), '2097-04-11', '2097-04-12'),
                $this->pricing(),
                $this->adminOptions($adminId, $this->adminCommand('UNUSED', '2097-04-11', '2097-04-12')),
            );
            self::fail('Blocked period overlap was accepted.');
        } catch (BookingConflict) {
            self::assertTrue(true);
        }

        $staleReference = 'ADMIN-STALE-' . strtoupper(bin2hex(random_bytes(5)));
        $staleCommand = $this->adminCommand($staleReference, '2097-05-10', '2097-05-12');
        try {
            $repository->create(
                $staleCommand,
                $this->pricing(),
                $this->adminOptions($adminId, $staleCommand, '30001.00'),
            );
            self::fail('Stale preview price was accepted.');
        } catch (BookingPreviewStale) {
            self::assertTrue(true);
        }
        $bookingCount = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
        $bookingCount->execute(['reference' => $staleReference]);
        self::assertSame(0, (int) $bookingCount->fetchColumn());
        $claimCount = $this->pdo->prepare('SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)');
        $claimCount->execute(['hash' => hash('sha256', $staleCommand->idempotencyKey)]);
        self::assertSame(0, (int) $claimCount->fetchColumn());
    }

    public function testAdminManualCreationRejectsSameIdempotencyKeyWithChangedPayload(): void
    {
        $adminId = $this->insertAdmin();
        $repository = new TransactionalBookingRepository($this->pdo);
        $key = 'admin-shared-' . bin2hex(random_bytes(10));
        $first = $repository->create(
            $this->adminCommand('ADMIN-IDEMPOTENT-' . strtoupper(bin2hex(random_bytes(4))), '2097-06-10', '2097-06-12', $key),
            $this->pricing(),
            $this->adminOptions($adminId, $this->adminCommand('UNUSED', '2097-06-10', '2097-06-12')),
        );
        $this->bookingIds[] = $first->bookingId;

        $this->expectException(IdempotencyConflict::class);
        $repository->create(
            $this->adminCommand('ADMIN-IDEMPOTENT-CHANGED-' . strtoupper(bin2hex(random_bytes(4))), '2097-06-12', '2097-06-14', $key),
            $this->pricing(),
            $this->adminOptions($adminId, $this->adminCommand('UNUSED', '2097-06-12', '2097-06-14')),
        );
    }

    public function testCompleteTransactionAndIdempotentReplay(): void
    {
        $command = $this->command('2040-01-10', '2040-01-13');
        $repository = new TransactionalBookingRepository($this->pdo);

        $created = $repository->create($command, $this->pricing());
        $this->bookingIds[] = $created->bookingId;
        $replayed = $repository->create($command, $this->pricing());

        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->bookingId, $replayed->bookingId);
        self::assertSame(2, $this->countBookingRows('booking_child_ages', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_status_history', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_pricing_snapshots', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('email_outbox', $created->bookingId));
        self::assertSame(1, $this->countBookingRows('booking_idempotency', $created->bookingId));
        $booking = $this->pdo->prepare(
            'SELECT booking_policy_accepted_at, booking_policy_version, booking_policy_url,
                    privacy_accepted_at, privacy_policy_version, privacy_policy_url,
                    house_rules_accepted_at, house_rules_url
             FROM bookings WHERE id = :id'
        );
        $booking->execute(['id' => $created->bookingId]);
        self::assertSame([
            'booking_policy_accepted_at' => '2040-01-01 12:00:00',
            'booking_policy_version' => 'test-v1',
            'booking_policy_url' => '/booking-policy',
            'privacy_accepted_at' => '2040-01-01 12:00:00',
            'privacy_policy_version' => 'privacy-test-v1',
            'privacy_policy_url' => '/adatkezelesi_tajekoztato',
            'house_rules_accepted_at' => '2040-01-01 12:00:00',
            'house_rules_url' => 'https://abata.hu/abata_hazirend.pdf',
        ], $booking->fetch(PDO::FETCH_ASSOC));
        $audit = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE event_type = 'booking_policy.accepted' AND target_type = 'booking' AND target_id = :id"
        );
        $audit->execute(['id' => (string) $created->bookingId]);
        self::assertSame(1, (int) $audit->fetchColumn());
        $privacyAudit = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs
             WHERE event_type = 'privacy_policy.accepted' AND target_type = 'booking' AND target_id = :id"
        );
        $privacyAudit->execute(['id' => (string) $created->bookingId]);
        self::assertSame(1, (int) $privacyAudit->fetchColumn());
        $houseRulesAudit = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'house_rules.accepted' AND target_type = 'booking' AND target_id = :id");
        $houseRulesAudit->execute(['id' => (string) $created->bookingId]);
        self::assertSame(1, (int) $houseRulesAudit->fetchColumn());
    }

    public function test_booking_creation_queues_one_guest_and_one_row_per_resolved_admin_without_replay_duplicates(): void
    {
        $provider = new class implements BookingNotificationRecipientProvider {
            public function bookingNotificationRecipients(): array
            {
                return ['admin1@example.invalid', 'Admin2@example.invalid', 'admin1@example.invalid'];
            }
        };
        $repository = new TransactionalBookingRepository(
            $this->pdo,
            bookingNotificationRecipients: new BookingNotificationRecipientResolver($provider, 'fallback@example.invalid'),
            bookingAdminBaseUrl: 'https://booking.example.invalid/admin/bookings',
        );
        $command = $this->command('2040-01-20', '2040-01-23');

        $created = $repository->create($command, $this->pricing());
        $this->bookingIds[] = $created->bookingId;
        $replayed = $repository->create($command, $this->pricing());

        self::assertTrue($replayed->replayed);
        $statement = $this->pdo->prepare(
            'SELECT message_type, recipient, subject, payload FROM email_outbox WHERE booking_id = :id ORDER BY id'
        );
        $statement->execute(['id' => $created->bookingId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(3, $rows);
        self::assertSame('booking_request_received', $rows[0]['message_type']);
        self::assertSame(['admin1@example.invalid', 'admin2@example.invalid'], array_column(array_slice($rows, 1), 'recipient'));
        foreach (array_slice($rows, 1) as $row) {
            self::assertSame('booking_request_admin_notification', $row['message_type']);
            self::assertSame('Új foglalási igény érkezett', $row['subject']);
            self::assertSame($rows[0]['payload'], $row['payload']);
        }
    }

    public function testSameKeyWithDifferentPayloadIsRejected(): void
    {
        $repository = new TransactionalBookingRepository($this->pdo);
        $first = $this->command('2040-02-10', '2040-02-13', 'stable-key');
        $created = $repository->create($first, $this->pricing());
        $this->bookingIds[] = $created->bookingId;

        $this->expectException(IdempotencyConflict::class);
        $repository->create($this->command('2040-02-11', '2040-02-14', 'stable-key'), $this->pricing());
    }

    public function testOverlappingPendingBookingIsRejected(): void
    {
        $repository = new TransactionalBookingRepository($this->pdo);
        $first = $repository->create($this->command('2040-03-10', '2040-03-13'), $this->pricing());
        $this->bookingIds[] = $first->bookingId;

        $this->expectException(BookingConflict::class);
        $repository->create($this->command('2040-03-11', '2040-03-14'), $this->pricing());
    }

    public function testConfirmedAndBlockedOverlapsAreRejected(): void
    {
        $confirmedId = $this->insertConfirmed('2040-04-10', '2040-04-13');
        $this->bookingIds[] = $confirmedId;
        $repository = new TransactionalBookingRepository($this->pdo);

        try {
            $repository->create($this->command('2040-04-11', '2040-04-12'), $this->pricing());
            self::fail('Confirmed overlap was accepted.');
        } catch (BookingConflict) {
            self::assertTrue(true);
        }

        $blocked = $this->pdo->prepare(
            'INSERT INTO blocked_periods (start_date, end_date, reason) VALUES (:start, :end, :reason)'
        );
        $blocked->execute(['start' => '2040-05-10', 'end' => '2040-05-13', 'reason' => 'Integration test']);
        $this->blockedIds[] = (int) $this->pdo->lastInsertId();

        $this->expectException(BookingConflict::class);
        $repository->create($this->command('2040-05-11', '2040-05-12'), $this->pricing());
    }

    public function testChildFailureRollsBackEveryRecordIncludingClaim(): void
    {
        $command = new BookingPersistenceCommand(
            'rollback-' . bin2hex(random_bytes(8)),
            hash('sha256', 'rollback-payload-' . random_bytes(4)),
            'ROLLBACK-' . strtoupper(bin2hex(random_bytes(5))),
            '2040-06-10',
            '2040-06-13',
            'Integration Guest',
            'integration@example.invalid',
            '+3612345678',
            2,
            [6, 99],
            null,
            '2040-01-01 12:00:00',
            'test-v1',
            '/booking-policy',
            '2040-01-01 12:00:00',
            'privacy-test-v1',
            '/adatkezelesi_tajekoztato',
            '2040-01-01 12:00:00',
            'https://abata.hu/abata_hazirend.pdf',
        );

        try {
            (new TransactionalBookingRepository($this->pdo))->create($command, $this->pricing());
            self::fail('Invalid child age did not fail.');
        } catch (\PDOException) {
            $booking = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
            $booking->execute(['reference' => $command->reference]);
            self::assertSame(0, (int) $booking->fetchColumn());
            $claim = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)'
            );
            $claim->execute(['hash' => hash('sha256', $command->idempotencyKey)]);
            self::assertSame(0, (int) $claim->fetchColumn());
        }
    }

    /** @dataProvider transactionalFailureStages */
    public function testFailureAfterBookingOrHistoryInsertRollsBackEveryPartialRecord(string $stage): void
    {
        $command = $this->command('2042-01-10', '2042-01-13');
        $repository = new TransactionalBookingRepository($this->pdo, static function (string $current) use ($stage): void {
            if ($current === $stage) {
                throw new \RuntimeException('Injected transaction failure.');
            }
        });

        try {
            $repository->create($command, $this->pricing());
            self::fail('Injected transaction failure was not raised.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected transaction failure.', $error->getMessage());
        }

        $booking = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
        $booking->execute(['reference' => $command->reference]);
        self::assertSame(0, (int) $booking->fetchColumn());
        $claim = $this->pdo->prepare('SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)');
        $claim->execute(['hash' => hash('sha256', $command->idempotencyKey)]);
        self::assertSame(0, (int) $claim->fetchColumn());
    }

    /** @return iterable<string, array{string}> */
    public static function transactionalFailureStages(): iterable
    {
        yield 'after booking insert' => ['booking_inserted'];
        yield 'after policy audit insert' => ['policy_audit_inserted'];
        yield 'after privacy audit insert' => ['privacy_audit_inserted'];
        yield 'after status history insert' => ['history_inserted'];
    }

    public function testSnapshotSerializationFailureRollsBackBookingHistoryAndChildren(): void
    {
        $command = $this->command('2040-07-10', '2040-07-13');
        $invalidSnapshot = new class implements BookingPricingProvider {
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
            {
                return new BookingPricing('30000.00', 'HUF', [
                    'version' => 1,
                    'invalid_utf8' => "\xB1\x31",
                ]);
            }
        };

        try {
            (new TransactionalBookingRepository($this->pdo))->create($command, $invalidSnapshot);
            self::fail('Invalid snapshot did not fail.');
        } catch (\JsonException) {
            $booking = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference = :reference');
            $booking->execute(['reference' => $command->reference]);
            self::assertSame(0, (int) $booking->fetchColumn());

            $claim = $this->pdo->prepare(
                'SELECT COUNT(*) FROM booking_idempotency WHERE key_hash = UNHEX(:hash)'
            );
            $claim->execute(['hash' => hash('sha256', $command->idempotencyKey)]);
            self::assertSame(0, (int) $claim->fetchColumn());
        }
    }

    public function testConcurrentIdempotentRequestsCreateOneBookingAndReturnSameResult(): void
    {
        $key = 'concurrent-key-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', 'identical-concurrent-payload');
        $reference = 'RACE-' . strtoupper(bin2hex(random_bytes(5)));
        $first = $this->startWorker($key, $hash, $reference, '2041-01-10', '2041-01-13');
        $second = $this->startWorker($key, $hash, $reference, '2041-01-10', '2041-01-13');

        $firstResult = $this->finishWorker($first);
        $secondResult = $this->finishWorker($second);

        self::assertTrue($firstResult['ok']);
        self::assertTrue($secondResult['ok']);
        self::assertSame($firstResult['booking_id'], $secondResult['booking_id']);
        self::assertNotSame($firstResult['replayed'], $secondResult['replayed']);
        $this->bookingIds[] = (int) $firstResult['booking_id'];
    }

    public function testConcurrentSameDateRequestsWithDifferentKeysProduceOnePendingWinner(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('proc_open is required for the real concurrency test.');
        $first = $this->startWorker(
            'inventory-a-' . bin2hex(random_bytes(8)), hash('sha256', 'inventory-a-' . random_bytes(8)),
            'INVENTORY-A-' . strtoupper(bin2hex(random_bytes(5))), '2041-01-20', '2041-01-23',
        );
        $second = $this->startWorker(
            'inventory-b-' . bin2hex(random_bytes(8)), hash('sha256', 'inventory-b-' . random_bytes(8)),
            'INVENTORY-B-' . strtoupper(bin2hex(random_bytes(5))), '2041-01-20', '2041-01-23',
        );

        $results = [$this->finishWorker($first), $this->finishWorker($second)];
        $winners = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $losers = array_values(array_filter($results, static fn (array $result): bool => !$result['ok']));

        self::assertCount(1, $winners);
        self::assertCount(1, $losers);
        self::assertSame(BookingConflict::class, $losers[0]['exception']);
        $this->bookingIds[] = (int) $winners[0]['booking_id'];
    }

    public function testInventoryLockMakesConcurrentCreateObserveNewConfirmedBooking(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT id FROM booking_inventory_locks WHERE id = 1 FOR UPDATE')->fetchColumn();
        $worker = $this->startWorker(
            'confirmed-race-' . bin2hex(random_bytes(8)), hash('sha256', 'confirmed-race-payload'),
            'CONFIRMED-RACE-' . strtoupper(bin2hex(random_bytes(5))), '2041-02-10', '2041-02-13',
        );

        usleep(200_000);
        $confirmedId = $this->insertConfirmed('2041-02-11', '2041-02-12');
        $this->bookingIds[] = $confirmedId;
        $this->pdo->commit();

        $result = $this->finishWorker($worker);
        self::assertFalse($result['ok']);
        self::assertSame(BookingConflict::class, $result['exception']);
    }

    private function command(string $arrival, string $departure, ?string $key = null): BookingPersistenceCommand
    {
        $key ??= 'key-' . bin2hex(random_bytes(10));
        $payload = implode('|', [$arrival, $departure, $key]);

        return new BookingPersistenceCommand(
            $key,
            hash('sha256', $payload),
            'PUBLIC-' . strtoupper(bin2hex(random_bytes(6))),
            $arrival,
            $departure,
            'Integration Guest',
            'integration@example.invalid',
            '+3612345678',
            2,
            [6, 9],
            'Integration note',
            '2040-01-01 12:00:00',
            'test-v1',
            '/booking-policy',
            '2040-01-01 12:00:00',
            'privacy-test-v1',
            '/adatkezelesi_tajekoztato',
            '2040-01-01 12:00:00',
            'https://abata.hu/abata_hazirend.pdf',
        );
    }

    private function adminCommand(string $reference, string $arrival, string $departure, ?string $key = null): BookingPersistenceCommand
    {
        $key ??= 'admin-key-' . bin2hex(random_bytes(10));

        return new BookingPersistenceCommand(
            $key,
            hash('sha256', implode('|', [$reference, $arrival, $departure, $key])),
            $reference,
            $arrival,
            $departure,
            'Admin Integration Guest',
            'admin-integration@example.invalid',
            '+3612345678',
            2,
            [6, 9],
            'Admin integration note',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    private function insertAdmin(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admins (email, password_hash, name) VALUES (:email, :password, :name)'
        );
        $statement->execute([
            'email' => 'phase9-' . bin2hex(random_bytes(6)) . '@example.invalid',
            'password' => password_hash('not-a-real-password', PASSWORD_DEFAULT),
            'name' => 'Phase 9 Integration Admin',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->adminIds[] = $id;

        return $id;
    }

    private function pricing(): BookingPricingProvider
    {
        return new class implements BookingPricingProvider {
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
            {
                return new BookingPricing('30000.00', 'HUF', [
                    'version' => 1,
                    'arrival_date' => $command->arrivalDate,
                    'departure_date' => $command->departureDate,
                    'total' => 30000,
                    'currency' => 'HUF',
                ]);
            }
        };
    }

    private function adminOptions(
        int $adminId,
        BookingPersistenceCommand $command,
        string $expectedCalculatedTotal = '30000.00',
    ): BookingCreationOptions {
        $snapshot = [
            'version' => 1,
            'arrival_date' => $command->arrivalDate,
            'departure_date' => $command->departureDate,
            'total' => 30000,
            'currency' => 'HUF',
        ];

        return BookingCreationOptions::adminManual(
            $adminId,
            $expectedCalculatedTotal,
            \App\Application\Booking\PricingSnapshotHasher::hash($snapshot),
            null,
            '30000.00',
            'HUF',
        );
    }

    private function countBookingRows(string $table, int $bookingId): int
    {
        $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE booking_id = :id', $table));
        $statement->execute(['id' => $bookingId]);

        return (int) $statement->fetchColumn();
    }

    private function insertConfirmed(string $arrival, string $departure): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children)
             VALUES (:reference, \'confirmed\', :arrival, :departure, :name, :email, 1, 0)'
        );
        $statement->execute([
            'reference' => 'CONFIRMED-' . strtoupper(bin2hex(random_bytes(5))),
            'arrival' => $arrival,
            'departure' => $departure,
            'name' => 'Integration Guest',
            'email' => 'integration@example.invalid',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{process: resource, pipes: array<int, resource>, output: string} */
    private function startWorker(string $key, string $hash, string $reference, string $arrival, string $departure): array
    {
        $output = tempnam(sys_get_temp_dir(), 'booking-race-');
        if ($output === false) {
            throw new \RuntimeException('Unable to allocate concurrency test output.');
        }
        $pipes = [];
        $process = proc_open([
            PHP_BINARY, __DIR__ . '/booking-create-worker.php', $output, $key, $hash, $reference, $arrival, $departure,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            @unlink($output);
            throw new \RuntimeException('Unable to start concurrency test worker.');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes, 'output' => $output];
    }

    /** @param array{process: resource, pipes: array<int, resource>, output: string} $worker */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        $json = file_get_contents($worker['output']);
        @unlink($worker['output']);
        self::assertSame(0, $exitCode, trim((string) $stdout . "\n" . (string) $stderr));
        self::assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
