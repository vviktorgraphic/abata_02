<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingManualCommunicationOutbox;
use App\Infrastructure\Persistence\Booking\PdoBookingModificationOutbox;
use App\Infrastructure\Persistence\Booking\PdoBookingPaymentRequestOutbox;
use App\Infrastructure\Persistence\Booking\PdoBookingStatusNotificationOutbox;
use PDO;
use PHPUnit\Framework\TestCase;

final class ManualEmailRetryCapTest extends TestCase
{
    private PDO $pdo;
    private int $bookingId;
    private int $adminId;
    private int $modificationId;
    private string $reference;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        $this->reference='CAP-'.strtoupper(bin2hex(random_bytes(5)));
        $q=$this->pdo->prepare("INSERT INTO bookings(reference,status,arrival_date,departure_date,guest_name,guest_email,adults,children,total_amount,currency)
            VALUES(:reference,'pending','2042-01-01','2042-01-03','Cap Test','cap@example.invalid',2,0,50000.00,'HUF')");
        $q->execute(['reference'=>$this->reference]);
        $this->bookingId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO admins(email,password_hash,name) VALUES(:email,'hash','Cap Admin')")
            ->execute(['email'=>'cap-'.bin2hex(random_bytes(5)).'@example.invalid']);
        $this->adminId=(int)$this->pdo->lastInsertId();
        $snapshot=json_encode(['arrival_date'=>'2042-01-01','departure_date'=>'2042-01-03','nights'=>2,'adults'=>2,'children'=>0,'child_ages'=>[],'accommodation_fee'=>'50000.00','tourism_tax'=>'0.00','total_amount'=>'50000.00','currency'=>'HUF'],JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO booking_modifications
            (booking_id,version,changed_by_admin_id,idempotency_key_hash,request_hash,before_snapshot,after_snapshot,currency)
            VALUES(:booking_id,1,:admin_id,UNHEX(:key_hash),UNHEX(:request_hash),:before_snapshot,:after_snapshot,\'HUF\')')
            ->execute(['booking_id'=>$this->bookingId,'admin_id'=>$this->adminId,'key_hash'=>str_repeat('1',64),'request_hash'=>str_repeat('2',64),'before_snapshot'=>$snapshot,'after_snapshot'=>$snapshot]);
        $this->modificationId=(int)$this->pdo->lastInsertId();
    }

    protected function tearDown():void
    {
        if(isset($this->bookingId))$this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id'=>$this->bookingId]);
        if(isset($this->adminId))$this->pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$this->adminId]);
    }

    public function testEveryManualRetryPathRefusesSixthAttempt():void
    {
        $this->seed(5);
        $config=$this->config();

        self::assertNull((new PdoBookingStatusNotificationOutbox($this->pdo))->findForDelivery($this->bookingId,'confirmed'));
        self::assertNull((new PdoBookingPaymentRequestOutbox($this->pdo))->claim($this->reference,$config));
        self::assertNull((new PdoBookingManualCommunicationOutbox($this->pdo))->claim($this->reference,'booking_payment_reminder',$config));
        $this->pdo->prepare("UPDATE bookings SET status='confirmed' WHERE id=:id")->execute(['id'=>$this->bookingId]);
        self::assertNull((new PdoBookingManualCommunicationOutbox($this->pdo))->claim($this->reference,'booking_arrival_information',$config));
        self::assertNull((new PdoBookingModificationOutbox($this->pdo))->claimForManualDelivery($this->reference,$this->modificationId));
        self::assertSame(5,(int)$this->pdo->query('SELECT MIN(attempts) FROM email_outbox WHERE booking_id='.(int)$this->bookingId)->fetchColumn());
    }

    public function testFourthAttemptRemainsOperatorClaimableForEveryManualPath():void
    {
        $this->seed(4);
        $config=$this->config();

        self::assertNotNull((new PdoBookingStatusNotificationOutbox($this->pdo))->findForDelivery($this->bookingId,'confirmed'));
        self::assertNotNull((new PdoBookingPaymentRequestOutbox($this->pdo))->claim($this->reference,$config));
        self::assertNotNull((new PdoBookingManualCommunicationOutbox($this->pdo))->claim($this->reference,'booking_payment_reminder',$config));
        $this->pdo->prepare("UPDATE bookings SET status='confirmed' WHERE id=:id")->execute(['id'=>$this->bookingId]);
        self::assertNotNull((new PdoBookingManualCommunicationOutbox($this->pdo))->claim($this->reference,'booking_arrival_information',$config));
        self::assertNotNull((new PdoBookingModificationOutbox($this->pdo))->claimForManualDelivery($this->reference,$this->modificationId));
    }

    private function seed(int $attempts):void
    {
        $status=['booking_reference'=>$this->reference,'arrival_date'=>'2042-01-01','departure_date'=>'2042-01-03','adults'=>2,'children'=>0,'total'=>'50000.00','currency'=>'HUF'];
        $payment=['booking_reference'=>$this->reference,'recipient'=>'cap@example.invalid','contact_name'=>'Cap Test','arrival_date'=>'2042-01-01','departure_date'=>'2042-01-03','currency'=>'HUF','total'=>'50000.00','advance_percent'=>50,'advance_amount'=>'25000.00','beneficiary'=>'Owner','bank_account'=>'Account','template_version'=>1];
        $reminder=['recipient'=>'cap@example.invalid','contact_name'=>'Cap Test','advance_amount'=>'25000.00','payment_reference'=>$this->reference,'beneficiary'=>'Owner','bank_account'=>'Account','bank_name'=>'Bank','swift_bic'=>'SWIFT','template_version'=>1];
        $arrival=['recipient'=>'cap@example.invalid','contact_name'=>'Cap Test','template_version'=>1];
        $modified=['contact_name'=>'Cap Test','booking_reference'=>$this->reference,'arrival_date'=>'2042-01-01','departure_date'=>'2042-01-03','nights'=>2,'adults'=>2,'child_ages'=>[],'accommodation_fee'=>'50000.00','tourism_tax'=>'0.00','total_amount'=>'50000.00','currency'=>'HUF'];
        foreach([
            ['booking_confirmed','',$status],['booking_payment_request','',$payment],
            ['booking_payment_reminder','',$reminder],['booking_arrival_information','',$arrival],
            ['booking_modified','modification:'.$this->modificationId,$modified],
        ] as [$type,$key,$payload]){
            $q=$this->pdo->prepare("INSERT INTO email_outbox(booking_id,message_type,deduplication_key,recipient,subject,payload,status,attempts)
                VALUES(:booking_id,:type,:deduplication_key,'cap@example.invalid','Subject',:payload,'failed',:attempts)");
            $q->execute(['booking_id'=>$this->bookingId,'type'=>$type,'deduplication_key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'attempts'=>$attempts]);
        }
    }

    private function config():BookingPaymentRequestConfiguration
    {
        return new BookingPaymentRequestConfiguration('Owner','Account',50,'Bank','SWIFT');
    }
}
