<?php
declare(strict_types=1);

namespace Tests\Integration\Pricing;

use App\Application\Audit\{AuditEvent, AuditLog};
use App\Application\Pricing\PricingVersionConflict;
use App\Application\Booking\BookingPersistenceCommand;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\{OccupancyDateOverride, OccupancyStayLengthBand};
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Pricing\PdoOccupancyPricingRepository;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoOccupancyPricingRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoOccupancyPricingRepository $repository;
    private array $events = [];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        // Connection-local temporary tables isolate mutations from other tests and existing data.
        foreach (['occupancy_pricing_configuration','occupancy_stay_length_bands','occupancy_date_overrides'] as $table) {
            $this->createTemporaryShadow($table);
        }
        $this->pdo->exec('INSERT INTO occupancy_pricing_configuration (id,version,one_night_surcharge) VALUES (1,1,0)');
        $events = &$this->events;
        $audit = new class($events) implements AuditLog {
            private array $events;
            public function __construct(array &$events) { $this->events = &$events; }
            public function append(AuditEvent $event): void { $this->events[]=$event; }
        };
        $this->repository = new PdoOccupancyPricingRepository($this->pdo,$audit);
    }

    public function testTaxSaveVersionAuditAndStaleWriteRollback(): void
    {
        self::assertSame('0.00',$this->repository->get()->tourismTaxPerPersonPerNight);
        $this->repository->saveTourismTax('500',1,1);
        self::assertSame('500.00',$this->repository->get()->tourismTaxPerPersonPerNight);
        self::assertSame(2,$this->repository->get()->version);
        self::assertSame('occupancy_pricing.tourism_tax_updated',$this->events[0]->eventType);
        try {
            $this->repository->saveTourismTax('900',1,1);
            self::fail('A stale version must fail.');
        } catch (PricingVersionConflict) {
            self::assertSame('500.00',$this->repository->get()->tourismTaxPerPersonPerNight);
            self::assertCount(1,$this->events);
        }
        $this->repository->saveTourismTax('0',2,1);
        self::assertSame('0.00',$this->repository->get()->tourismTaxPerPersonPerNight);
        self::assertSame(3,$this->repository->get()->version);
    }

    public function testAdminTaxFormParsesGroupedHufAndEnforcesCsrfAndVersion(): void
    {
        $auth = $this->createStub(\App\Http\Controller\Admin\AdminAuthWorkflow::class);
        $auth->method('currentAdmin')->willReturn(['id' => 1, 'name' => 'Test']);
        $session = $this->createStub(\App\Security\Session\SessionStorage::class);
        $session->method('get')->willReturn('test-csrf');
        $csrf = new \App\Security\Csrf\CsrfTokenManager($session);
        $limiter = $this->createStub(\App\Http\Controller\Admin\AdminActionRateLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $controller = new \App\Http\Controller\Admin\OccupancyPricingAdminController(
            $auth, new \App\Http\Controller\Admin\AdminView(dirname(__DIR__, 3) . '/templates'), $csrf,
            new \App\Http\Controller\Admin\AdminActionGuard($auth, $csrf, $limiter), $this->repository,
        );
        $form = ['action' => 'tourism_tax', 'version' => '1', 'amount' => '1 000'];
        self::assertSame(403, $controller->save($form, 'application/x-www-form-urlencoded', 100)->status);
        $form['_csrf'] = 'test-csrf';
        self::assertInstanceOf(\App\Http\Controller\Admin\RedirectResponse::class, $controller->save($form, 'application/x-www-form-urlencoded', 100));
        self::assertSame('1000.00', $this->repository->get()->tourismTaxPerPersonPerNight);
        self::assertSame(409, $controller->save($form, 'application/x-www-form-urlencoded', 100)->status);
        $form['version'] = '2';
        $form['amount'] = '22.50';
        self::assertSame(422, $controller->save($form, 'application/x-www-form-urlencoded', 100)->status);
        self::assertSame('1000.00', $this->repository->get()->tourismTaxPerPersonPerNight);
        self::assertSame(2, $this->repository->get()->version);
    }

    public function testFullYearOverrideSavesAllFourPricesAndRejectsOverlap(): void
    {
        $prices=[1=>'22000',2=>'27000',3=>'37000',4=>'42000'];
        $id=$this->repository->saveOverride(new OccupancyDateOverride(0,'2027-01-01','2027-12-31',$prices,true,3,5),1,1);
        $stored=$this->repository->get()->overrides[0];
        self::assertSame($id,$stored->id);
        self::assertSame(3,$stored->minNights);
        self::assertSame(5,$stored->maxNights);
        foreach ($prices as $guests=>$price) self::assertSame($price.'.00',$stored->priceFor($guests));
        $this->repository->saveOverride(new OccupancyDateOverride($id,'2027-01-01','2027-12-31',$prices,true,2,8),2,1);
        $stored=$this->repository->get()->overrides[0];
        self::assertSame(2,$stored->minNights);
        self::assertSame(8,$stored->maxNights);
        try {
            $this->repository->saveOverride(new OccupancyDateOverride(0,'2027-12-31','2028-01-02',$prices),3,1);
            self::fail('An overlapping active override must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nem fedhetik át egymást',$e->getMessage());
            self::assertSame(3,$this->repository->get()->version);
            self::assertCount(1,$this->repository->get()->overrides);
        }
    }

    public function testExistingOverrideRowsReceiveMigrationDefaults(): void
    {
        $this->pdo->exec("INSERT INTO occupancy_date_overrides
            (start_date,end_date,price_1_guest,price_2_guests,price_3_guests,price_4_guests,is_active)
            VALUES ('2028-01-01','2028-01-03',1,2,3,4,1)");

        $stored = $this->repository->get()->overrides[0];
        self::assertSame(1, $stored->minNights);
        self::assertNull($stored->maxNights);
    }

    public function testBaseOverlapPreservesExistingBandAndGivesUsefulMessage(): void
    {
        $this->repository->saveBand(new OccupancyStayLengthBand(0,1,1,null,'22000'),1,1);
        try {
            $this->repository->saveBand(new OccupancyStayLengthBand(0,1,2,7,'23000'),2,1);
            self::fail('An overlapping active base band must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Előbb módosítsa vagy inaktiválja',$e->getMessage());
            self::assertStringContainsString('1 fő, 1 éjszakától korlátlan ideig',$e->getMessage());
            self::assertCount(1,$this->repository->get()->bands);
            self::assertTrue($this->repository->get()->bands[0]->active);
        }
    }

    public function testSavedTaxIsSharedByBookingAndQuoteAdminPreviewBoundary(): void
    {
        $this->createTemporaryShadow('pricing_rules');
        $this->repository->saveBand(new OccupancyStayLengthBand(0,2,1,null,'27000'),1,1);
        $this->repository->saveTourismTax('500',2,1);
        $adapter = new PdoPricingEngineAdapter($this->pdo);
        // Public realtime quote and admin preview both call this same preview method.
        $preview = $adapter->preview(new PricingInput('2027-01-01','2027-01-04',2));
        $command = new BookingPersistenceCommand(
            'test-key',str_repeat('a',64),'TEST-TAX','2027-01-01','2027-01-04',
            'Test','test@example.invalid','123',2,[],null,
            '2026-10-05 12:00:00','v1','/policy','2026-10-05 12:00:00','v1','/privacy'
        );
        $booking = $adapter->calculate($this->pdo,$command);
        self::assertSame('3000.00',$preview->snapshot['taxes']);
        self::assertSame('84000.00',$preview->totalAmount);
        self::assertSame($preview->totalAmount,$booking->totalAmount);
        foreach (['tourism_tax_per_person_per_night','tourism_tax_quantity','taxes','total'] as $field) {
            self::assertSame($preview->snapshot[$field],$booking->snapshot[$field]);
        }
    }

    private function createTemporaryShadow(string $table): void
    {
        $source = $table . '_test_source';
        $this->pdo->exec('CREATE TEMPORARY TABLE ' . $source . ' LIKE ' . $table);
        $this->pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' LIKE ' . $source);
        $this->pdo->exec('DROP TEMPORARY TABLE ' . $source);
    }
}
