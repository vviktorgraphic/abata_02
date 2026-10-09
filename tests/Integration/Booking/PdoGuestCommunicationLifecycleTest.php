<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\BookingLifecycleWorker;
use App\Application\Mail\BookingManualCommunicationDispatcher;
use App\Application\Mail\BookingManualCommunicationRenderer;
use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingLifecycleRepository;
use App\Infrastructure\Persistence\Booking\PdoBookingManualCommunicationOutbox;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoGuestCommunicationLifecycleTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */ private array $ids=[];

    protected function setUp():void
    { if(getenv('DB_HOST')===false)self::markTestSkipped('Database not configured.');$this->pdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php'); }
    protected function tearDown():void
    { foreach($this->ids as $id){$this->pdo->prepare("DELETE FROM audit_logs WHERE target_type='booking' AND target_id=:id")->execute(['id'=>(string)$id]);$this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id'=>$id]);} }

    public function testReminderRequiresSentPaymentUsesFrozenPayloadRetriesAndDoesNotDuplicate():void
    {
        [$id,$reference]=$this->booking('pending','2040-01-01','2040-01-03');
        $outbox=new PdoBookingManualCommunicationOutbox($this->pdo);$config=$this->config();
        try{$outbox->claim($reference,'booking_payment_reminder',$config);self::fail('Reminder accepted without sent payment.');}
        catch(\DomainException $e){self::assertSame('Emlékeztető csak sikeresen elküldött díjbekérő után küldhető.',$e->getMessage());}
        $this->paymentSent($id,'31000.00','AB-000777');
        $mailer=new InMemoryMailer();$dispatcher=new BookingManualCommunicationDispatcher($outbox,$this->manualRenderer(),$mailer,$config);
        self::assertSame('sent',$dispatcher->paymentReminder($reference)->status);
        self::assertSame('sent',$dispatcher->paymentReminder($reference)->status);
        self::assertCount(1,$mailer->messages());self::assertStringContainsString('31 000 Ft',$mailer->lastMessage()->textBody);self::assertStringContainsString('AB-000777',$mailer->lastMessage()->textBody);
    }

    public function testArrivalOnlyConfirmedAndHasRetrySafeSingleOutbox():void
    {
        [$id,$reference]=$this->booking('pending','2040-02-01','2040-02-03');$outbox=new PdoBookingManualCommunicationOutbox($this->pdo);
        try{$outbox->claim($reference,'booking_arrival_information',$this->config());self::fail('Pending arrival accepted.');}
        catch(\DomainException $e){self::assertSame('Érkezési tájékoztató csak megerősített foglaláshoz küldhető.',$e->getMessage());}
        $this->pdo->prepare("UPDATE bookings SET status='confirmed' WHERE id=:id")->execute(['id'=>$id]);
        $mailer=new InMemoryMailer();$dispatcher=new BookingManualCommunicationDispatcher($outbox,$this->manualRenderer(),$mailer,$this->config());
        self::assertSame('sent',$dispatcher->arrivalInformation($reference)->status);self::assertSame('sent',$dispatcher->arrivalInformation($reference)->status);
        self::assertCount(1,$mailer->messages());self::assertCount(4,$mailer->lastMessage()->inlineAttachments);
    }

    public function testLifecycleCatchesUpWithinActivationWindowAndRemainsIdempotent():void
    {
        [$exactReviewId]=$this->booking('confirmed','2026-10-01','2026-10-10');
        [$oneDayLateId]=$this->booking('confirmed','2026-10-01','2026-10-09');
        [$severalDaysLateId]=$this->booking('confirmed','2026-10-01','2026-10-05');
        [$historicalId]=$this->booking('confirmed','2026-09-01','2026-09-30');
        [$pendingId]=$this->booking('pending','2026-10-01','2026-10-10');
        [$rejectedId]=$this->booking('rejected','2026-10-01','2026-10-10');
        [$cancelledId]=$this->booking('cancelled','2026-10-01','2026-10-10');
        [$invalidatedId]=$this->booking('invalidated','2026-10-01','2026-10-10');
        $mailer=new InMemoryMailer();$worker=$this->worker($mailer,'2026-10-10 00:01:00','2026-10-01');
        self::assertSame(['review_sent'=>3,'review_failed'=>0,'completed'=>2],$worker->run());
        self::assertSame(['review_sent'=>0,'review_failed'=>0,'completed'=>0],$worker->run());
        self::assertCount(3,$mailer->messages());
        self::assertSame('confirmed',$this->bookingStatus($exactReviewId));
        self::assertSame('completed',$this->bookingStatus($oneDayLateId));
        self::assertSame('completed',$this->bookingStatus($severalDaysLateId));
        foreach ([$historicalId=>'confirmed',$pendingId=>'pending',$rejectedId=>'rejected',$cancelledId=>'cancelled',$invalidatedId=>'invalidated'] as $id=>$status) {
            self::assertSame($status,$this->bookingStatus($id));
            self::assertSame(0,$this->reviewOutboxCount($id));
        }
        foreach ([$exactReviewId,$oneDayLateId,$severalDaysLateId] as $id) self::assertSame('sent',$this->reviewStatus($id));
        foreach ([$oneDayLateId,$severalDaysLateId] as $id) {
            self::assertSame(1,$this->completionHistoryCount($id));
            self::assertSame(1,$this->completionAuditCount($id));
        }
    }

    public function testFailedReviewRetriesAfterIndependentCompletionAndSentNeverDuplicates():void
    {
        [$id]=$this->booking('confirmed','2026-10-01','2026-10-09');
        $failingMailer=new class implements Mailer {
            public int $attempts=0;
            public function send(Message $message):void{$this->attempts++;throw new \RuntimeException('simulated transport failure');}
        };
        $first=$this->worker($failingMailer,'2026-10-10 01:00:00','2026-10-01');
        self::assertSame(['review_sent'=>0,'review_failed'=>1,'completed'=>1],$first->run());
        self::assertSame(1,$failingMailer->attempts);
        self::assertSame('completed',$this->bookingStatus($id));
        self::assertSame('failed',$this->reviewStatus($id));
        self::assertSame(1,$this->reviewAttempts($id));

        $mailer=new InMemoryMailer();$retry=$this->worker($mailer,'2026-10-11 01:00:00','2026-10-01');
        self::assertSame(['review_sent'=>1,'review_failed'=>0,'completed'=>0],$retry->run());
        self::assertSame(['review_sent'=>0,'review_failed'=>0,'completed'=>0],$retry->run());
        self::assertCount(1,$mailer->messages());
        self::assertSame('sent',$this->reviewStatus($id));
        self::assertSame(2,$this->reviewAttempts($id));
        self::assertSame(1,$this->completionHistoryCount($id));
        self::assertSame(1,$this->completionAuditCount($id));
        self::assertSame('A Bata',$mailer->lastMessage()->fromName);
        self::assertSame('info@abata.test',$mailer->lastMessage()->replyToEmail);
    }

    /** @return array{int,string} */ private function booking(string $status,string $arrival,string $departure):array
    { $reference='PH3-'.strtoupper(bin2hex(random_bytes(5)));$q=$this->pdo->prepare('INSERT INTO bookings(reference,status,arrival_date,departure_date,guest_name,guest_email,total_amount) VALUES(:reference,:status,:arrival,:departure,\'Guest\',:email,64000.00)');$q->execute(['reference'=>$reference,'status'=>$status,'arrival'=>$arrival,'departure'=>$departure,'email'=>$reference.'@example.invalid']);$id=(int)$this->pdo->lastInsertId();$this->ids[]=$id;return[$id,$reference]; }
    private function paymentSent(int $id,string $amount,string $paymentReference):void
    { $payload=['booking_reference'=>'BOOK','payment_reference'=>$paymentReference,'recipient'=>'guest@example.invalid','contact_name'=>'Guest','arrival_date'=>'2040-01-01','departure_date'=>'2040-01-03','currency'=>'HUF','accommodation_fee'=>'62000.00','taxes'=>'2000.00','total'=>'64000.00','advance_percent'=>50,'advance_amount'=>$amount,'beneficiary'=>'Frozen owner','bank_name'=>'Frozen bank','bank_account'=>'Frozen account','swift_bic'=>'FROZENSWIFT','template_version'=>2];$q=$this->pdo->prepare("INSERT INTO email_outbox(booking_id,message_type,recipient,subject,payload,status,sent_at) VALUES(:id,'booking_payment_request','guest@example.invalid','Sent',:payload,'sent',CURRENT_TIMESTAMP)");$q->execute(['id'=>$id,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]); }
    private function bookingStatus(int $id):string{$q=$this->pdo->prepare('SELECT status FROM bookings WHERE id=:id');$q->execute(['id'=>$id]);return(string)$q->fetchColumn();}
    private function reviewOutboxCount(int $id):int{$q=$this->pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:id AND message_type='booking_review_request'");$q->execute(['id'=>$id]);return(int)$q->fetchColumn();}
    private function reviewStatus(int $id):string{$q=$this->pdo->prepare("SELECT status FROM email_outbox WHERE booking_id=:id AND message_type='booking_review_request'");$q->execute(['id'=>$id]);return(string)$q->fetchColumn();}
    private function reviewAttempts(int $id):int{$q=$this->pdo->prepare("SELECT attempts FROM email_outbox WHERE booking_id=:id AND message_type='booking_review_request'");$q->execute(['id'=>$id]);return(int)$q->fetchColumn();}
    private function completionHistoryCount(int $id):int{$q=$this->pdo->prepare("SELECT COUNT(*) FROM booking_status_history WHERE booking_id=:id AND old_status='confirmed' AND new_status='completed'");$q->execute(['id'=>$id]);return(int)$q->fetchColumn();}
    private function completionAuditCount(int $id):int{$q=$this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE target_type='booking' AND target_id=:id AND event_type='booking.completed_auto'");$q->execute(['id'=>(string)$id]);return(int)$q->fetchColumn();}
    private function config():BookingPaymentRequestConfiguration{return new BookingPaymentRequestConfiguration('Owner','Account',50,'Bank','SWIFT');}
    private function manualRenderer():BookingManualCommunicationRenderer
    {
        return new BookingManualCommunicationRenderer(
            dirname(__DIR__,3).'/templates/email', dirname(__DIR__,3).'/resources/email/arrival',
            'from@example.test', 'A Bata', 'info@abata.test', 'A Bata',
        );
    }
    private function worker(Mailer $mailer,string $now,string $startDate):BookingLifecycleWorker
    {
        return new BookingLifecycleWorker(
            new PdoBookingLifecycleRepository($this->pdo),
            new BookingReviewMailRenderer(
                dirname(__DIR__,3).'/templates/email','from@example.test','A Bata','info@abata.test','A Bata',
            ),
            $mailer,
            $startDate,
            null,
            static fn()=>new \DateTimeImmutable($now,new \DateTimeZone('Europe/Budapest')),
        );
    }
}
