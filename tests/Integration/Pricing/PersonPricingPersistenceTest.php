<?php

declare(strict_types=1);

namespace Tests\Integration\Pricing;

use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Pricing\MissingChildPriceBandException;
use App\Application\Pricing\PricingVersionConflict;
use App\Domain\Pricing\ChildPriceBand;
use App\Domain\Pricing\PersonPricingConfiguration;
use App\Domain\Pricing\PricingInput;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Pricing\PdoPersonPricingRepository;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use App\Infrastructure\Persistence\Pricing\PdoPricingRuleRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PersonPricingPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    /** @var array<string,mixed> */
    private array $originalConfiguration;
    /** @var list<array<string,mixed>> */
    private array $originalBands;
    /** @var list<array<string,mixed>> */
    private array $originalCoverage;
    /** @var list<int> */
    private array $bookingIds = [];
    /** @var list<int> */
    private array $ruleIds = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Database environment is not configured.');
        }
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        // Keep this legacy/person integration suite isolated after the additive
        // occupancy migration: an empty connection-local configuration makes the
        // shared adapter exercise its documented person-mode compatibility path.
        foreach (['occupancy_pricing_configuration', 'occupancy_stay_length_bands', 'occupancy_date_overrides'] as $table) {
            $this->createEmptyTemporaryShadow($table);
        }
        $this->originalConfiguration = $this->pdo->query('SELECT * FROM person_pricing_configuration WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $this->originalBands = $this->pdo->query('SELECT * FROM pricing_child_bands ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->originalCoverage = $this->pdo->query('SELECT * FROM pricing_child_age_coverage ORDER BY age')->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->exec('DELETE FROM pricing_child_age_coverage');
        $this->pdo->exec('DELETE FROM pricing_child_bands');
        $this->pdo->exec("UPDATE person_pricing_configuration SET pricing_mode='legacy', adult_weekday_price=NULL, adult_weekend_price=NULL, updated_by_admin_id=NULL");
        $insert = $this->pdo->prepare('INSERT INTO admins (email,password_hash,name) VALUES (:email,:hash,:name)');
        $insert->execute([
            'email' => 'person-pricing-' . bin2hex(random_bytes(5)) . '@example.invalid',
            'hash' => password_hash('irrelevant-test-password', PASSWORD_DEFAULT),
            'name' => 'Person Pricing Test',
        ]);
        $this->adminId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) { return; }
        foreach ($this->bookingIds as $id) {
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type='booking' AND target_id=:id")->execute(['id'=>(string)$id]);
            $this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id'=>$id]);
        }
        foreach ($this->ruleIds as $id) {
            $this->pdo->prepare('DELETE FROM pricing_rules WHERE id=:id')->execute(['id'=>$id]);
        }
        $this->pdo->prepare("DELETE FROM audit_logs WHERE event_type IN ('person_pricing.updated','person_pricing.band_deleted') AND admin_id=:id")->execute(['id'=>$this->adminId]);
        $this->pdo->exec('DELETE FROM pricing_child_age_coverage');
        $this->pdo->exec('DELETE FROM pricing_child_bands');
        foreach ($this->originalBands as $band) {
            $statement = $this->pdo->prepare('INSERT INTO pricing_child_bands
                (id,min_age,max_age,weekday_price,weekend_price,is_active,sort_order) VALUES
                (:id,:min_age,:max_age,:weekday_price,:weekend_price,:is_active,:sort_order)');
            $statement->execute(array_intersect_key($band, array_flip(['id','min_age','max_age','weekday_price','weekend_price','is_active','sort_order'])));
        }
        foreach ($this->originalCoverage as $row) {
            $this->pdo->prepare('INSERT INTO pricing_child_age_coverage (age,band_id) VALUES (:age,:band_id)')->execute($row);
        }
        $restore = $this->pdo->prepare('UPDATE person_pricing_configuration SET version=:version, pricing_mode=:pricing_mode,
            adult_weekday_price=:adult_weekday_price, adult_weekend_price=:adult_weekend_price,
            updated_by_admin_id=:updated_by_admin_id, updated_at=:updated_at WHERE id=1');
        $restore->execute(array_intersect_key($this->originalConfiguration, array_flip([
            'version','pricing_mode','adult_weekday_price','adult_weekend_price','updated_by_admin_id','updated_at',
        ])));
        $this->pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$this->adminId]);
    }

    public function testTransactionalSaveAuditsAndEnforcesVersionRatesAndDatabaseCoverage(): void
    {
        $repository = new PdoPersonPricingRepository($this->pdo);
        $current = $repository->get();
        $saved = $repository->save(new PersonPricingConfiguration($current->version, 'person', '10000', '12000', [
            new ChildPriceBand(0, 5, '0', '0'), new ChildPriceBand(6, 17, '5000', '6000'),
        ]), $current->version, $this->adminId);

        self::assertSame($current->version + 1, $saved->version);
        self::assertSame('10000.00', $saved->adultWeekdayPrice);
        self::assertSame(18, (int) $this->pdo->query('SELECT COUNT(*) FROM pricing_child_age_coverage')->fetchColumn());
        $audit = $this->pdo->prepare("SELECT metadata_json FROM audit_logs WHERE event_type='person_pricing.updated' AND admin_id=:id");
        $audit->execute(['id'=>$this->adminId]);
        self::assertSame($saved->version, json_decode((string)$audit->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)['version']);

        try {
            $repository->save($saved, $current->version, $this->adminId);
            self::fail('A stale pricing version was accepted.');
        } catch (PricingVersionConflict) {
            self::addToAssertionCount(1);
        }
        try {
            $repository->save(new PersonPricingConfiguration($saved->version, 'person'), $saved->version, $this->adminId);
            self::fail('Person mode was enabled without adult prices.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            $bandId = $saved->childBands[1]->id;
            $this->pdo->prepare('INSERT INTO pricing_child_age_coverage (age,band_id) VALUES (5,:band)')->execute(['band'=>$bandId]);
            self::fail('Database overlap coverage was accepted.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->pdo->exec("UPDATE person_pricing_configuration SET adult_weekday_price=1.50 WHERE id=1");
            self::fail('Fractional HUF configuration was accepted by the database.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
    }

    public function testPreviewBookingIdempotencySnapshotAndCancellationUseOnePersistedModel(): void
    {
        $configuration = $this->configure([
            new ChildPriceBand(0, 2, '0', '0'),
            new ChildPriceBand(3, 6, '5000', '6000'),
            new ChildPriceBand(7, 13, '8000', '9000'),
            new ChildPriceBand(14, 17, '12000', '14000'),
        ], '20000', '25000');
        $rule = (new PdoPricingRuleRepository($this->pdo))->create([
            'name'=>'Test IFA','rule_type'=>'tourism_tax','valid_from'=>'2044-01-01','valid_until'=>'2045-01-01',
            'nightly_price'=>'500.00','amount'=>'500.00','adjustment_mode'=>'fixed','base_unit'=>'per_person_per_night',
            'currency'=>'HUF','minimum_nights'=>1,'maximum_nights'=>null,'applicable_weekdays'=>null,
            'exemption_key'=>null,'priority'=>901,'is_active'=>1,
        ], $this->adminId);
        $this->ruleIds[] = $rule;
        $adapter = new PdoPricingEngineAdapter($this->pdo);
        $preview = $adapter->preview(new PricingInput('2044-08-04','2044-08-07',2,[4,10]));
        $command = $this->command('2044-08-04','2044-08-07',[4,10]);
        $repository = new TransactionalBookingRepository($this->pdo, null, null,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2044-08-01 12:00:00', new \DateTimeZone('Europe/Budapest')));
        $created = $repository->create($command, $adapter);
        $this->bookingIds[] = $created->bookingId;
        self::assertSame($preview->totalAmount, $created->totalAmount);
        self::assertSame('183000.00', $preview->accommodationFee);
        self::assertSame('3000.00', $preview->tourismTax);
        self::assertSame('186000.00', $preview->totalAmount);
        $snapshotBefore = $this->snapshot($created->bookingId);
        self::assertSame(3, $snapshotBefore['version']);
        self::assertSame($configuration->version, $snapshotBefore['pricing_configuration_version']);
        self::assertCount(3, $snapshotBefore['nightly_breakdown']);
        self::assertFalse($snapshotBefore['nightly_breakdown'][0]['weekend']);
        self::assertTrue($snapshotBefore['nightly_breakdown'][1]['weekend']);
        self::assertTrue($snapshotBefore['nightly_breakdown'][2]['weekend']);
        $mailPayload = json_decode((string) $this->pdo->query("SELECT payload FROM email_outbox WHERE booking_id=" . (int) $created->bookingId . " AND message_type='booking_request_received'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([4, 10], $mailPayload['child_ages']);
        self::assertSame('186000.00', $mailPayload['total']);
        // Preview and booking are two calculations, so their audit timestamps may cross a second boundary.
        $previewSnapshot = $preview->snapshot;
        $persistedSnapshot = $snapshotBefore;
        foreach ([$previewSnapshot['calculated_at'], $persistedSnapshot['calculated_at']] as $calculatedAt) {
            $parsed = new \DateTimeImmutable((string) $calculatedAt);
            self::assertSame((string) $calculatedAt, $parsed->format(DATE_ATOM));
            self::assertSame(
                $parsed->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('P'),
                $parsed->format('P'),
            );
        }
        unset($previewSnapshot['calculated_at'], $persistedSnapshot['calculated_at']);
        // MySQL JSON may canonicalize object key order; all business content must stay equal.
        self::assertEquals($previewSnapshot, $persistedSnapshot);

        $current = (new PdoPersonPricingRepository($this->pdo))->get();
        (new PdoPersonPricingRepository($this->pdo))->save(new PersonPricingConfiguration(
            $current->version, 'person', '90000', '99000', $current->childBands,
        ), $current->version, $this->adminId);
        $replayed = $repository->create($command, $adapter);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->bookingId, $replayed->bookingId);
        self::assertSame($snapshotBefore, $this->snapshot($created->bookingId));
        self::assertNotSame($preview->totalAmount, $adapter->preview(new PricingInput('2044-08-04','2044-08-07',2,[4,10]))->totalAmount);

        $this->pdo->prepare("INSERT INTO email_outbox (booking_id, message_type, recipient, subject, payload, status, sent_at)
            VALUES (:id, 'booking_payment_request', 'guest@example.test', 'Test payment', '{}', 'sent', CURRENT_TIMESTAMP)")
            ->execute(['id' => $created->bookingId]);
        $repository->transition($created->reference, 'confirmed', $this->adminId);
        $repository->transition($created->reference, 'cancelled', $this->adminId);
        $row = $this->pdo->query('SELECT cancellation_penalty_amount,cancellation_calculation_snapshot FROM bookings WHERE id='.(int)$created->bookingId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->half((string)$snapshotBefore['accommodation_fee']), (string)$row['cancellation_penalty_amount']);
        $cancellation = json_decode((string)$row['cancellation_calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($snapshotBefore['accommodation_fee'], $cancellation['accommodation_fee']);
        self::assertNotSame($snapshotBefore['total'], $cancellation['accommodation_fee']);

        $personRepository = new PdoPersonPricingRepository($this->pdo);
        $beforeDelete = $personRepository->get();
        $bandId = $beforeDelete->bandForAge(4)->id;
        $snapshotJsonBeforeDelete = (string) $this->pdo->query('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id=' . (int) $created->bookingId)->fetchColumn();
        $coverageBeforeDelete = (int) $this->pdo->query('SELECT COUNT(*) FROM pricing_child_age_coverage')->fetchColumn();
        try {
            $personRepository->deleteBand($bandId, $beforeDelete->version - 1, $this->adminId);
            self::fail('A stale pricing version deleted a band.');
        } catch (PricingVersionConflict) {
            self::assertSame($beforeDelete->version, $personRepository->get()->version);
            self::assertSame($coverageBeforeDelete, (int) $this->pdo->query('SELECT COUNT(*) FROM pricing_child_age_coverage')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event_type='person_pricing.band_deleted' AND admin_id=" . $this->adminId)->fetchColumn());
        }
        $afterDelete = $personRepository->deleteBand($bandId, $beforeDelete->version, $this->adminId);
        self::assertCount(3, $afterDelete->childBands);
        self::assertSame(14, (int) $this->pdo->query('SELECT COUNT(*) FROM pricing_child_age_coverage')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pricing_child_age_coverage WHERE age BETWEEN 3 AND 6')->fetchColumn());
        self::assertSame($beforeDelete->version + 1, $afterDelete->version);
        self::assertSame($snapshotJsonBeforeDelete, (string) $this->pdo->query('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id=' . (int) $created->bookingId)->fetchColumn());
        $deletedAudit = $this->pdo->prepare("SELECT metadata_json FROM audit_logs WHERE event_type='person_pricing.band_deleted' AND admin_id=:id");
        $deletedAudit->execute(['id'=>$this->adminId]);
        $deletedMetadata = json_decode((string) $deletedAudit->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((string) $bandId, $deletedMetadata['target_id']);
        self::assertSame($afterDelete->version, $deletedMetadata['version']);
    }

    public function testMissingChildBandRollsBackBookingOutboxAndIdempotencyClaim(): void
    {
        $this->configure([new ChildPriceBand(0, 5, '0', '0')]);
        $command = $this->command('2046-08-05','2046-08-06',[9]);
        try {
            (new TransactionalBookingRepository($this->pdo))->create($command, new PdoPricingEngineAdapter($this->pdo));
            self::fail('A booking with an uncovered child age was persisted.');
        } catch (MissingChildPriceBandException) {
            self::addToAssertionCount(1);
        }
        $reference = $this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE reference=:reference');
        $reference->execute(['reference'=>$command->reference]);
        self::assertSame(0, (int)$reference->fetchColumn());
        $claim = $this->pdo->prepare('SELECT COUNT(*) FROM booking_idempotency WHERE key_hash=UNHEX(:hash)');
        $claim->execute(['hash'=>hash('sha256',$command->idempotencyKey)]);
        self::assertSame(0, (int)$claim->fetchColumn());
    }

    /** @param list<ChildPriceBand> $bands */
    private function configure(array $bands, string $adultWeekday = '10000', string $adultWeekend = '12000'): PersonPricingConfiguration
    {
        $repository = new PdoPersonPricingRepository($this->pdo);
        $current = $repository->get();
        return $repository->save(new PersonPricingConfiguration($current->version,'person',$adultWeekday,$adultWeekend,$bands),
            $current->version,$this->adminId);
    }

    /** @param list<int> $ages */
    private function command(string $arrival, string $departure, array $ages): BookingPersistenceCommand
    {
        $key = 'person-' . bin2hex(random_bytes(10));
        return new BookingPersistenceCommand($key, hash('sha256',$key.'|'.$arrival.'|'.$departure),
            'PERSON-' . strtoupper(bin2hex(random_bytes(6))), $arrival, $departure, 'Pricing Guest',
            'pricing@example.invalid', '+3612345678', 2, $ages, null,
            '2044-01-01 12:00:00', 'test-v1', '/booking-policy',
            '2044-01-01 12:00:00', 'privacy-v1', '/privacy',
            '2044-01-01 12:00:00', 'https://abata.hu/abata_hazirend.pdf');
    }

    /** @return array<string,mixed> */
    private function snapshot(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id=:id');
        $statement->execute(['id'=>$bookingId]);
        return json_decode((string)$statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function half(string $amount): string
    {
        $whole = (int)explode('.', $amount, 2)[0];
        return intdiv($whole * 50 + 50, 100) . '.00';
    }

    private function createEmptyTemporaryShadow(string $table): void
    {
        $source = $table . '_person_test_source';
        $this->pdo->exec('CREATE TEMPORARY TABLE ' . $source . ' LIKE ' . $table);
        $this->pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' LIKE ' . $source);
        $this->pdo->exec('DROP TEMPORARY TABLE ' . $source);
    }
}
