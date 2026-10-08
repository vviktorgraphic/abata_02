<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Booking\BookingLifecycleWorker;
use App\Application\Mail\BookingManualCommunicationDispatcher;
use App\Application\Mail\BookingManualCommunicationRenderer;
use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Application\Mail\InMemoryMailer;
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

    public function testLifecycleUsesExactDatesNoCatchupAndCompletesExactlyOnce():void
    {
        [$reviewId]=$this->booking('confirmed','2026-10-01','2026-10-08');
        [$completeId]=$this->booking('confirmed','2026-10-01','2026-10-07');
        [$historicalId]=$this->booking('confirmed','2026-09-01','2026-09-02');
        [$pendingId]=$this->booking('pending','2026-10-01','2026-10-08');
        $mailer=new InMemoryMailer();$worker=new BookingLifecycleWorker(new PdoBookingLifecycleRepository($this->pdo),
            new BookingReviewMailRenderer(
                dirname(__DIR__,3).'/templates/email', 'from@example.test', 'A Bata',
                'info@abata.test', 'A Bata',
            ), $mailer, null,
            static fn()=>new \DateTimeImmutable('2026-10-08 00:01:00',new \DateTimeZone('Europe/Budapest')));
        self::assertSame(['review_sent'=>1,'review_failed'=>0,'completed'=>1],$worker->run());
        self::assertSame(['review_sent'=>0,'review_failed'=>0,'completed'=>0],$worker->run());self::assertCount(1,$mailer->messages());
        self::assertSame('A Bata', $mailer->lastMessage()->fromName);
        self::assertSame('info@abata.test', $mailer->lastMessage()->replyToEmail);
        self::assertSame('A Bata', $mailer->lastMessage()->replyToName);
        self::assertSame('completed',$this->bookingStatus($completeId));self::assertSame('confirmed',$this->bookingStatus($reviewId));self::assertSame('confirmed',$this->bookingStatus($historicalId));self::assertSame('pending',$this->bookingStatus($pendingId));
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM booking_status_history WHERE booking_id=:id AND new_status='completed'");$q->execute(['id'=>$completeId]);self::assertSame(1,(int)$q->fetchColumn());
    }

    /** @return array{int,string} */ private function booking(string $status,string $arrival,string $departure):array
    { $reference='PH3-'.strtoupper(bin2hex(random_bytes(5)));$q=$this->pdo->prepare('INSERT INTO bookings(reference,status,arrival_date,departure_date,guest_name,guest_email,total_amount) VALUES(:reference,:status,:arrival,:departure,\'Guest\',:email,64000.00)');$q->execute(['reference'=>$reference,'status'=>$status,'arrival'=>$arrival,'departure'=>$departure,'email'=>$reference.'@example.invalid']);$id=(int)$this->pdo->lastInsertId();$this->ids[]=$id;return[$id,$reference]; }
    private function paymentSent(int $id,string $amount,string $paymentReference):void
    { $payload=['booking_reference'=>'BOOK','payment_reference'=>$paymentReference,'recipient'=>'guest@example.invalid','contact_name'=>'Guest','arrival_date'=>'2040-01-01','departure_date'=>'2040-01-03','currency'=>'HUF','accommodation_fee'=>'62000.00','taxes'=>'2000.00','total'=>'64000.00','advance_percent'=>50,'advance_amount'=>$amount,'beneficiary'=>'Frozen owner','bank_name'=>'Frozen bank','bank_account'=>'Frozen account','swift_bic'=>'FROZENSWIFT','template_version'=>2];$q=$this->pdo->prepare("INSERT INTO email_outbox(booking_id,message_type,recipient,subject,payload,status,sent_at) VALUES(:id,'booking_payment_request','guest@example.invalid','Sent',:payload,'sent',CURRENT_TIMESTAMP)");$q->execute(['id'=>$id,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]); }
    private function bookingStatus(int $id):string{$q=$this->pdo->prepare('SELECT status FROM bookings WHERE id=:id');$q->execute(['id'=>$id]);return(string)$q->fetchColumn();}
    private function config():BookingPaymentRequestConfiguration{return new BookingPaymentRequestConfiguration('Owner','Account',50,'Bank','SWIFT');}
    private function manualRenderer():BookingManualCommunicationRenderer
    {
        return new BookingManualCommunicationRenderer(
            dirname(__DIR__,3).'/templates/email', dirname(__DIR__,3).'/resources/email/arrival',
            'from@example.test', 'A Bata', 'info@abata.test', 'A Bata',
        );
    }
}
