<?php
declare(strict_types=1);
namespace Tests\Integration\Booking;

use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingModificationNotAllowed;
use App\Application\Booking\BookingModificationStale;
use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Application\Booking\ConfirmedBookingModification;
use App\Application\Mail\BookingModificationMailRenderer;
use App\Application\Mail\BookingModificationNotificationDispatcher;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingModificationOutbox;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\PdoEmailOutboxWorkerRepository;
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
        self::assertSame(0,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));
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

    public function testCustomPriceModificationPersistsEffectiveSnapshotPreservesProvenanceDepositAndManualEmail(): void
    {
        $id = $this->booking('confirmed', '2044-11-10', '2044-11-12', []);
        $service = $this->service();
        $firstRequest = new ConfirmedBookingModification('2044-11-14', '2044-11-17', 2, [], '100000.00');
        $firstPreview = $service->preview($this->reference($id), $firstRequest);

        self::assertSame('100000.00', $firstPreview->pricing->totalAmount);
        self::assertSame($firstPreview->calculatedPricing->tourismTax, $firstPreview->pricing->tourismTax);
        self::assertSame(
            100000 - (int) substr($firstPreview->pricing->tourismTax, 0, -3),
            (int) substr($firstPreview->pricing->accommodationFee, 0, -3),
        );
        self::assertSame('admin_custom_total', $firstPreview->pricing->snapshot['price_override']['type']);

        $first = $service->modify(
            $this->reference($id), $firstRequest, 0, $firstPreview->pricingHash,
            'custom-modification-key-0001', $this->adminId,
        );
        self::assertSame('100000.00', $this->value('SELECT total_amount FROM bookings WHERE id=:id', ['id' => $id]));
        self::assertSame('45000.00', $this->value(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.advance_amount')) FROM email_outbox WHERE booking_id=:id AND message_type='booking_payment_request'",
            ['id' => $id],
        ));
        self::assertSame(0, (int) $this->value(
            "SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",
            ['id' => $id],
        ));

        $secondRequest = new ConfirmedBookingModification('2044-11-15', '2044-11-19', 2, [], '110000.00');
        $secondPreview = $service->preview($this->reference($id), $secondRequest);
        $second = $service->modify(
            $this->reference($id), $secondRequest, 1, $secondPreview->pricingHash,
            'custom-modification-key-0002', $this->adminId,
        );
        $stored = $this->row(
            'SELECT before_snapshot,after_snapshot,unchanged_deposit_amount FROM booking_modifications WHERE id=:id',
            ['id' => $second->modificationId],
        );
        $before = json_decode((string) $stored['before_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $stored['after_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('100000.00', $before['pricing_snapshot']['price_override']['custom_total']);
        self::assertSame('110000.00', $after['pricing_snapshot']['price_override']['custom_total']);
        self::assertSame('admin_custom_total', $after['pricing_mode']);
        self::assertSame('45000.00', $stored['unchanged_deposit_amount']);

        $mailer = new InMemoryMailer();
        $dispatcher = new BookingModificationNotificationDispatcher(
            new PdoBookingModificationOutbox($this->pdo), $this->mailRenderer(), $mailer
        );
        self::assertSame('sent', $dispatcher->dispatch($this->reference($id), $second->modificationId, $this->adminId)->status);
        self::assertCount(1, $mailer->messages());
        self::assertStringContainsString('Egyedi végösszeg:', $mailer->messages()[0]->textBody);
        self::assertStringContainsString('110 000 Ft', $mailer->messages()[0]->textBody);
        self::assertStringContainsString(
            \App\Presentation\HufFormatter::format($secondPreview->pricing->accommodationFee),
            $mailer->messages()[0]->textBody,
        );
        self::assertSame(1, $first->version);
    }

    public function testCustomModificationWithoutPaymentRequestKeepsDepositNull(): void
    {
        $id = $this->booking('confirmed', '2044-12-10', '2044-12-12', []);
        $this->pdo->prepare("DELETE FROM email_outbox WHERE booking_id=:id AND message_type='booking_payment_request'")
            ->execute(['id' => $id]);
        $service = $this->service();
        $request = new ConfirmedBookingModification('2044-12-14', '2044-12-17', 2, [], '100000.00');
        $preview = $service->preview($this->reference($id), $request);
        self::assertNull($preview->unchangedDepositAmount);

        $result = $service->modify(
            $this->reference($id), $request, 0, $preview->pricingHash,
            'custom-no-deposit-key-0001', $this->adminId,
        );
        self::assertNull($this->value(
            'SELECT unchanged_deposit_amount FROM booking_modifications WHERE id=:id',
            ['id' => $result->modificationId],
        ));
        self::assertSame(0, (int) $this->value(
            "SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_payment_request'",
            ['id' => $id],
        ));
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
        self::assertSame(0,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));
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
        $service=new TransactionalConfirmedBookingModificationService($this->pdo,new PdoPricingEngineAdapter($this->pdo),static function(string $stage):void{if($stage==='audit_inserted')throw new \RuntimeException('injected');});
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

    public function testOnlyLatestModificationCanBeManuallyNotifiedAndDeliveryIsIdempotent(): void
    {
        $id=$this->booking('confirmed','2044-08-10','2044-08-12',[]);
        $service=$this->service();
        $v1=$this->modifyTo($service,$id,'2044-08-13','2044-08-15','manual-notification-key-0001');
        $v2=$this->modifyTo($service,$id,'2044-08-14','2044-08-17','manual-notification-key-0002');
        $v3=$this->modifyTo($service,$id,'2044-08-15','2044-08-19','manual-notification-key-0003');
        self::assertSame(0,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));
        $detail=(new PdoAdminBookingQueryRepository($this->pdo))->fetchBookingDetail(new \App\Application\Booking\AdminBookingDetailQuery($this->reference($id)));
        self::assertSame([true,false,false],array_column($detail['modifications'],'is_latest'));

        $mailer=new InMemoryMailer();
        $dispatcher=new BookingModificationNotificationDispatcher(
            new PdoBookingModificationOutbox($this->pdo),$this->mailRenderer(),$mailer
        );
        foreach([$v1->modificationId,$v2->modificationId] as $olderId){
            try{$dispatcher->dispatch($this->reference($id),$olderId,$this->adminId);self::fail('Older notification must be rejected.');}
            catch(\DomainException $error){self::assertSame('Csak a legutóbbi módosításról küldhető értesítés.',$error->getMessage());}
        }
        self::assertCount(0,$mailer->messages());
        self::assertSame(0,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));

        self::assertSame('sent',$dispatcher->dispatch($this->reference($id),$v3->modificationId,$this->adminId)->status);
        self::assertSame('sent',$dispatcher->dispatch($this->reference($id),$v3->modificationId,$this->adminId)->status);
        self::assertCount(1,$mailer->messages());
        self::assertStringContainsString('2044-08-15',$mailer->messages()[0]->textBody);
        self::assertStringContainsString('2044-08-19',$mailer->messages()[0]->textBody);
        $outbox=$this->row("SELECT status,attempts,deduplication_key FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]);
        self::assertSame('sent',$outbox['status']);
        self::assertSame(1,(int)$outbox['attempts']);
        self::assertSame('modification:'.$v3->modificationId,$outbox['deduplication_key']);
    }

    public function testExplicitManualFailureIsEligibleForPhase10Retry(): void
    {
        $id=$this->booking('confirmed','2044-09-10','2044-09-12',[]);
        $result=$this->modifyTo($this->service(),$id,'2044-09-13','2044-09-16','manual-failure-key-0001');
        $mailer=new class implements Mailer {
            public int $calls=0;
            public function send(Message $message):void{$this->calls++;throw new \RuntimeException('private transport detail');}
        };
        $dispatcher=new BookingModificationNotificationDispatcher(
            new PdoBookingModificationOutbox($this->pdo),$this->mailRenderer(),$mailer
        );
        self::assertSame('failed',$dispatcher->dispatch($this->reference($id),$result->modificationId,$this->adminId)->status);
        self::assertSame(1,$mailer->calls);
        $outbox=$this->row("SELECT id,status,attempts,UNIX_TIMESTAMP(updated_at) AS updated_epoch FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]);
        self::assertSame('failed',$outbox['status']);
        self::assertSame(1,(int)$outbox['attempts']);
        $due=(new \DateTimeImmutable('@'.((int)$outbox['updated_epoch']+300)))->setTimezone(new \DateTimeZone('Europe/Budapest'));
        $claimed=(new PdoEmailOutboxWorkerRepository($this->pdo))->claimNextDue($due,['booking_modified']);
        self::assertNotNull($claimed);
        self::assertSame((int)$outbox['id'],$claimed->id);
        self::assertSame(1,$claimed->attempts);
    }

    public function testConcurrentManualIntentCreatesAndClaimsExactlyOneOutboxRow():void
    {
        if(!function_exists('proc_open'))self::markTestSkipped('proc_open is required for the real concurrency test.');
        $id=$this->booking('confirmed','2044-10-10','2044-10-12',[]);
        $result=$this->modifyTo($this->service(),$id,'2044-10-13','2044-10-16','manual-concurrency-key-0001');
        $barrier=tempnam(sys_get_temp_dir(),'modification-notification-barrier-');
        self::assertIsString($barrier);
        unlink($barrier);
        $worker=__DIR__.'/booking-modification-notification-worker.php';
        $processes=[];
        foreach([1,2] as $_){
            $pipes=[];
            $process=proc_open([PHP_BINARY,$worker,$this->reference($id),(string)$result->modificationId,$barrier],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process);
            $processes[]=[$process,$pipes];
        }
        touch($barrier);
        $outputs=[];
        foreach($processes as [$process,$pipes]){
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$stderr?:$stdout);
            $outputs[]=trim($stdout);
        }
        unlink($barrier);
        sort($outputs);
        self::assertSame(['CLAIMED','NOT_CLAIMED'],$outputs);
        $outbox=$this->row("SELECT status,attempts FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]);
        self::assertSame('processing',$outbox['status']);
        self::assertSame(0,(int)$outbox['attempts']);
        self::assertSame(1,(int)$this->value("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_modified'",['id'=>$id]));
    }

    private function service(): TransactionalConfirmedBookingModificationService{return new TransactionalConfirmedBookingModificationService($this->pdo,new PdoPricingEngineAdapter($this->pdo));}
    private function modifyTo(TransactionalConfirmedBookingModificationService $service,int $bookingId,string $arrival,string $departure,string $key): \App\Application\Booking\BookingModificationResult
    {
        $request=new ConfirmedBookingModification($arrival,$departure,2,[]);
        $preview=$service->preview($this->reference($bookingId),$request);
        $version=(int)$this->value('SELECT modification_version FROM bookings WHERE id=:id',['id'=>$bookingId]);
        return $service->modify($this->reference($bookingId),$request,$version,$preview->pricingHash,$key,$this->adminId);
    }
    private function mailRenderer():BookingModificationMailRenderer
    {
        return new BookingModificationMailRenderer(dirname(__DIR__,3).'/templates/email','noreply@example.test','A Bata','info@example.test','A Bata');
    }
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
