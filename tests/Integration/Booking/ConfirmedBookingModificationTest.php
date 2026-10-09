<?php
declare(strict_types=1);
namespace Tests\Integration\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingModificationNotAllowed;
use App\Application\Booking\BookingModificationStale;
use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Application\Booking\ConfirmedBookingModification;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\TransactionalConfirmedBookingModificationService;
use App\Infrastructure\Persistence\Booking\PdoAdminMonthlyOccupancyRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarExportFeedRepository;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use App\Infrastructure\Persistence\PdoBookingReadRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConfirmedBookingModificationTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    /** @var list<int> */ private array $bookingIds=[];
    /** @var list<int> */ private array $blockedIds=[];

    protected function setUp(): void
    {
        if (getenv('DB_HOST')===false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        $statement=$this->pdo->prepare("INSERT INTO admins (email,password_hash,name) VALUES (:email,'hash','Modification Test')");
        $statement->execute(['email'=>'modify-'.bin2hex(random_bytes(5)).'@example.test']);
        $this->adminId=(int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach($this->bookingIds as $id){
            $this->pdo->prepare("DELETE FROM audit_logs WHERE target_type='booking' AND target_id=:id")->execute(['id'=>(string)$id]);
            $this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id'=>$id]);
        }
        foreach($this->blockedIds as $id)$this->pdo->prepare('DELETE FROM blocked_periods WHERE id=:id')->execute(['id'=>$id]);
        $this->pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$this->adminId]);
    }

    public function testPreviewAndSaveRepriceAtomicallyPreserveDepositAndDoNotWriteStatusHistory(): void
    {
        $id=$this->booking('confirmed','2044-02-10','2044-02-12',[3]);
        $request=new ConfirmedBookingModification('2044-02-14','2044-02-17',2,[5]);
        $service=$this->service();
        $preview=$service->preview($this->reference($id),$request);
        $calendar=new PdoCalendarExportFeedRepository($this->pdo);
        $uid=hash('sha256','foglalasi-rendszer-ical:booking:'.$this->reference($id)).'@calendar.local';
        $oldEvent=array_values(array_filter($calendar->exportableEvents(),static fn($event):bool=>$event->uid===$uid))[0];
        self::assertMatchesRegularExpression('/^\d+\.00$/',$preview->pricing->accommodationFee);
        self::assertMatchesRegularExpression('/^\d+\.00$/',$preview->pricing->tourismTax);
        self::assertSame('45000.00',$preview->unchangedDepositAmount);

        $result=$service->modify($this->reference($id),$request,0,$preview->pricingHash,'modification-key-0001',$this->adminId);
        self::assertSame(1,$result->version);
        $booking=$this->row('SELECT * FROM bookings WHERE id=:id',['id'=>$id]);
        self::assertSame('confirmed',$booking['status']);
        self::assertSame('2044-02-14',$booking['arrival_date']);
        self::assertSame('2044-02-17',$booking['departure_date']);
        self::assertSame($preview->pricing->totalAmount,$booking['total_amount']);
        self::assertSame(1,(int)$booking['modification_version']);
        self::assertSame([5],array_map('intval',$this->column('SELECT age FROM booking_child_ages WHERE booking_id=:id ORDER BY position',['id'=>$id])));
        self::assertSame(0,(int)$this->value('SELECT COUNT(*) FROM booking_status_history WHERE booking_id=:id',['id'=>$id]));
        self::assertSame(1,(int)$this->value("SELECT COUNT(*) FROM audit_logs WHERE target_id=:id AND event_type='booking_modified'",['id'=>(string)$id]));
        $outbox=$this->row("SELECT * FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]);
        self::assertSame('pending',$outbox['status']);
        self::assertSame('modification:'.$result->modificationId,$outbox['deduplication_key']);
        $payment=json_decode((string)$this->value("SELECT payload FROM email_outbox WHERE booking_id=:id AND message_type='booking_payment_request'",['id'=>$id]),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('45000.00',$payment['advance_amount']);
        $snapshot=json_decode((string)$this->value('SELECT snapshot FROM booking_pricing_snapshots WHERE booking_id=:id',['id'=>$id]),true,512,JSON_THROW_ON_ERROR);
        self::assertSame($preview->pricing->accommodationFee,$snapshot['accommodation_fee']);
        $newEvent=array_values(array_filter($calendar->exportableEvents(),static fn($event):bool=>$event->uid===$uid))[0];
        self::assertSame($oldEvent->uid,$newEvent->uid);
        self::assertSame('2044-02-14',$newEvent->startDate->format('Y-m-d'));
        self::assertSame('2044-02-17',$newEvent->endDate->format('Y-m-d'));
        $periods=(new PdoBookingReadRepository($this->pdo))->findBlockingBetween(
            new \DateTimeImmutable('2044-02-09',new \DateTimeZone('Europe/Budapest')),
            new \DateTimeImmutable('2044-02-18',new \DateTimeZone('Europe/Budapest')),
        );
        $ranges=array_map(static fn($period):string=>$period->arrival->format('Y-m-d').'/'.$period->departure->format('Y-m-d'),$periods);
        self::assertContains('2044-02-14/2044-02-17',$ranges);
        self::assertNotContains('2044-02-10/2044-02-12',$ranges);
        $monthly=(new PdoAdminMonthlyOccupancyRepository($this->pdo))->fetch(new AdminMonthlyOccupancyQuery(
            '2044-02',new \DateTimeImmutable('2044-02-01',new \DateTimeZone('Europe/Budapest'))
        ));
        $oldDay=array_values(array_filter($monthly['days'],static fn(array $day):bool=>$day['date']==='2044-02-10'))[0];
        $newDay=array_values(array_filter($monthly['days'],static fn(array $day):bool=>$day['date']==='2044-02-14'))[0];
        self::assertNotContains($this->reference($id),array_column($oldDay['bookings'],'reference'));
        self::assertContains($this->reference($id),array_column($newDay['bookings'],'reference'));
    }

    public function testSameIdempotencyKeyReplaysWithoutSecondAuditOrEmailAndStaleVersionIsRejected(): void
    {
        $id=$this->booking('confirmed','2044-03-10','2044-03-12',[]);
        $request=new ConfirmedBookingModification('2044-03-14','2044-03-16',2,[]);
        $service=$this->service();
        $pricingHash=$service->preview($this->reference($id),$request)->pricingHash;
        $first=$service->modify($this->reference($id),$request,0,$pricingHash,'modification-key-0002',$this->adminId);
        $second=$service->modify($this->reference($id),$request,0,$pricingHash,'modification-key-0002',$this->adminId);
        self::assertTrue($second->idempotentReplay);
        self::assertSame($first->modificationId,$second->modificationId);
        self::assertSame(1,(int)$this->value('SELECT COUNT(*) FROM booking_modifications WHERE booking_id=:id',['id'=>$id]));
        self::assertSame(1,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));
        $this->expectException(BookingModificationStale::class);
        $staleRequest=new ConfirmedBookingModification('2044-03-17','2044-03-19',2,[]);
        $service->modify($this->reference($id),$staleRequest,0,str_repeat('a',64),'modification-key-0003',$this->adminId);
    }

    public function testBookingAndBlockedPeriodConflictsUseHalfOpenIntervalsAndExcludeCurrentBooking(): void
    {
        $id=$this->booking('confirmed','2044-04-10','2044-04-12',[]);
        $other=$this->booking('pending','2044-04-15','2044-04-18',[]);
        $service=$this->service();
        $adjacent=new ConfirmedBookingModification('2044-04-12','2044-04-15',2,[]);
        self::assertSame('2044-04-12',$service->preview($this->reference($id),$adjacent)->modification->arrivalDate);
        try{$service->preview($this->reference($id),new ConfirmedBookingModification('2044-04-14','2044-04-16',2,[]));self::fail('Conflict expected.');}
        catch(BookingConflict){}
        $this->pdo->prepare("INSERT INTO blocked_periods(start_date,end_date,reason,is_active) VALUES('2044-04-20','2044-04-22','external test',TRUE)")->execute();
        $this->blockedIds[]=(int)$this->pdo->lastInsertId();
        $this->expectException(BookingConflict::class);
        $service->preview($this->reference($id),new ConfirmedBookingModification('2044-04-21','2044-04-23',2,[]));
    }

    public function testOnlyConfirmedBookingCanBeModified(): void
    {
        $id=$this->booking('pending','2044-05-10','2044-05-12',[]);
        $this->expectException(BookingModificationNotAllowed::class);
        $this->service()->preview($this->reference($id),new ConfirmedBookingModification('2044-05-13','2044-05-15',2,[]));
    }

    public function testPricingChangeAfterPreviewRequiresFreshPreview(): void
    {
        $id=$this->booking('confirmed','2044-07-10','2044-07-12',[]);
        $request=new ConfirmedBookingModification('2044-07-14','2044-07-16',2,[]);
        $service=$this->service();
        $preview=$service->preview($this->reference($id),$request);
        $original=(string)$this->value('SELECT tourism_tax_per_person_per_night FROM occupancy_pricing_configuration WHERE id=1',[]);
        $changed=((int)$original+1).'.00';
        try {
            $this->pdo->prepare('UPDATE occupancy_pricing_configuration SET tourism_tax_per_person_per_night=:tax, version=version+1 WHERE id=1')->execute(['tax'=>$changed]);
            $this->expectException(BookingModificationStale::class);
            $service->modify($this->reference($id),$request,0,$preview->pricingHash,'modification-key-0005',$this->adminId);
        } finally {
            $this->pdo->prepare('UPDATE occupancy_pricing_configuration SET tourism_tax_per_person_per_night=:tax, version=version+1 WHERE id=1')->execute(['tax'=>$original]);
        }
    }

    public function testInjectedFailureRollsBackBookingChildrenSnapshotAuditAndOutbox(): void
    {
        $id=$this->booking('confirmed','2044-06-10','2044-06-12',[2]);
        $service=new TransactionalConfirmedBookingModificationService($this->pdo,new PdoPricingEngineAdapter($this->pdo),static function(string $stage):void{if($stage==='outbox_inserted')throw new \RuntimeException('injected');});
        $request=new ConfirmedBookingModification('2044-06-14','2044-06-17',2,[6]);
        $pricingHash=$service->preview($this->reference($id),$request)->pricingHash;
        try{$service->modify($this->reference($id),$request,0,$pricingHash,'modification-key-0004',$this->adminId);self::fail('Failure expected.');}
        catch(\RuntimeException $error){self::assertSame('injected',$error->getMessage());}
        $booking=$this->row('SELECT arrival_date,departure_date,modification_version FROM bookings WHERE id=:id',['id'=>$id]);
        self::assertSame('2044-06-10',$booking['arrival_date']);
        self::assertSame(0,(int)$booking['modification_version']);
        self::assertSame([2],array_map('intval',$this->column('SELECT age FROM booking_child_ages WHERE booking_id=:id',['id'=>$id])));
        self::assertSame(0,(int)$this->value('SELECT COUNT(*) FROM booking_modifications WHERE booking_id=:id',['id'=>$id]));
        self::assertSame(0,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));
    }

    private function service(): TransactionalConfirmedBookingModificationService{return new TransactionalConfirmedBookingModificationService($this->pdo,new PdoPricingEngineAdapter($this->pdo));}
    /** @param list<int> $ages */
    private function booking(string $status,string $arrival,string $departure,array $ages):int
    {
        $reference='MOD-'.bin2hex(random_bytes(5));
        $statement=$this->pdo->prepare("INSERT INTO bookings(reference,status,arrival_date,departure_date,guest_name,guest_email,adults,children,total_amount,currency) VALUES(:reference,:status,:arrival,:departure,'Guest','guest@example.test',2,:children,50000,'HUF')");
        $statement->execute(compact('reference','status','arrival','departure')+['children'=>count($ages)]);
        $id=(int)$this->pdo->lastInsertId();$this->bookingIds[]=$id;
        $age=$this->pdo->prepare('INSERT INTO booking_child_ages(booking_id,position,age) VALUES(:id,:position,:age)');
        foreach($ages as $position=>$value)$age->execute(['id'=>$id,'position'=>$position,'age'=>$value]);
        $snapshot=['version'=>4,'arrival_date'=>$arrival,'departure_date'=>$departure,'accommodation_fee'=>'45000.00','taxes'=>'5000.00','total'=>'50000.00','currency'=>'HUF','line_items'=>[]];
        $this->pdo->prepare('INSERT INTO booking_pricing_snapshots(booking_id,snapshot) VALUES(:id,:snapshot)')->execute(['id'=>$id,'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR)]);
        $payload=['advance_amount'=>'45000.00','booking_reference'=>$reference];
        $this->pdo->prepare("INSERT INTO email_outbox(booking_id,message_type,recipient,subject,payload,status,sent_at) VALUES(:id,'booking_payment_request','guest@example.test','payment',:payload,'sent',CURRENT_TIMESTAMP)")->execute(['id'=>$id,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
        return $id;
    }
    private function reference(int $id):string{return (string)$this->value('SELECT reference FROM bookings WHERE id=:id',['id'=>$id]);}
    /** @param array<string,mixed> $params @return array<string,mixed> */ private function row(string $sql,array $params):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch(PDO::FETCH_ASSOC);}
    /** @param array<string,mixed> $params */ private function value(string $sql,array $params):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    /** @param array<string,mixed> $params @return list<mixed> */ private function column(string $sql,array $params):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_COLUMN);}
}
