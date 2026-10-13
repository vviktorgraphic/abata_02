<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Mail\EmailOutboxRetryPolicy;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingRequestOutbox;
use App\Infrastructure\Persistence\Booking\PdoEmailOutboxWorkerRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoEmailOutboxWorkerRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $bookingId;
    /** @var list<string> */
    private array $types = ['booking_request_received','booking_confirmed'];

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) self::markTestSkipped('Database environment is not configured.');
        $this->pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
        $statement = $this->pdo->prepare("INSERT INTO bookings
            (reference,status,arrival_date,departure_date,guest_name,guest_email,adults,children,total_amount,currency)
            VALUES(:reference,'pending','2042-01-01','2042-01-03','Worker Test','worker@example.invalid',2,0,50000.00,'HUF')");
        $statement->execute(['reference'=>'WORKER-'.strtoupper(bin2hex(random_bytes(5)))]);
        $this->bookingId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->bookingId)) {
            $this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id'=>$this->bookingId]);
        }
    }

    public function testPendingIsImmediateFailedRespectsBoundaryAndFifthAttemptNeverClaims(): void
    {
        $now=$this->now();
        $pending=$this->insert('booking_request_received','pending',0,$now);
        $failedEarly=$this->insert('booking_confirmed','failed',1,$now->modify('-299 seconds'),'early@example.invalid');
        $failedDue=$this->insert('booking_confirmed','failed',1,$now->modify('-300 seconds'),'due@example.invalid');
        $final=$this->insert('booking_confirmed','failed',5,$now->modify('-1 day'),'final@example.invalid');
        $repository=new PdoEmailOutboxWorkerRepository($this->pdo);

        self::assertSame($pending,$repository->claimNextDue($now,$this->types)?->id);
        self::assertSame($failedDue,$repository->claimNextDue($now,$this->types)?->id);
        self::assertNull($repository->claimNextDue($now,$this->types));
        self::assertSame('failed',$this->row($failedEarly)['status']);
        self::assertSame(5,(int)$this->row($final)['attempts']);
    }

    public function testWorkerNeverInitiatesPendingModificationButStillClaimsOtherPendingTypes(): void
    {
        $now=$this->now();
        $unrequested=$this->insert('booking_modified','pending',0,$now,'modified@example.invalid');
        $normal=$this->insert('booking_confirmed','pending',0,$now,'confirmed@example.invalid');
        $repository=new PdoEmailOutboxWorkerRepository($this->pdo);

        self::assertSame($normal,$repository->claimNextDue($now,['booking_modified','booking_confirmed'])?->id);
        self::assertNull($repository->claimNextDue($now,['booking_modified','booking_confirmed']));
        self::assertSame('pending',$this->row($unrequested)['status']);
        self::assertSame(0,(int)$this->row($unrequested)['attempts']);
    }

    public function testStaleRecoveryIsConditionalIncrementsAndDoesNotImmediatelyRetry(): void
    {
        $now=$this->now();
        $fresh=$this->insert('booking_request_received','processing',0,$now->modify('-899 seconds'),'fresh@example.invalid');
        $stale=$this->insert('booking_confirmed','processing',0,$now->modify('-900 seconds'),'stale@example.invalid');
        $final=$this->insert('booking_confirmed','processing',4,$now->modify('-901 seconds'),'stale-final@example.invalid');
        $repository=new PdoEmailOutboxWorkerRepository($this->pdo);

        $recovered=$repository->recoverStale($now,$this->types,25);

        self::assertSame([$stale,$final],array_map(static fn($item):int=>$item->id,$recovered));
        self::assertSame('processing',$this->row($fresh)['status']);
        self::assertSame(1,(int)$this->row($stale)['attempts']);
        self::assertSame('Stale processing lease recovered.',$this->row($stale)['last_error']);
        self::assertSame(5,(int)$this->row($final)['attempts']);
        self::assertNull($repository->claimNextDue($now,$this->types));
    }

    public function testFinalizationUsesExpectedClaimStateAndSafeReasons(): void
    {
        $now=$this->now();
        $id=$this->insert('booking_confirmed','failed',1,$now->modify('-5 minutes'));
        $repository=new PdoEmailOutboxWorkerRepository($this->pdo);
        $item=$repository->claimNextDue($now,$this->types);
        self::assertNotNull($item);

        self::assertTrue($repository->markTransportFailed($item,$now));
        self::assertFalse($repository->markTransportFailed($item,$now));
        $row=$this->row($id);
        self::assertSame('failed',$row['status']);
        self::assertSame(2,(int)$row['attempts']);
        self::assertSame('E-mail transport failure.',$row['last_error']);

        $payloadId=$this->insert('booking_confirmed','pending',0,$now,'payload@example.invalid');
        $payload=$repository->claimNextDue($now,$this->types);
        self::assertNotNull($payload);
        self::assertTrue($repository->markPayloadFailed($payload,$now));
        self::assertSame(5,(int)$this->row($payloadId)['attempts']);
        self::assertSame('Stored e-mail payload could not be rendered.',$this->row($payloadId)['last_error']);
    }

    public function testGlobalLockIsNonBlockingAndReleased(): void
    {
        $first=new PdoEmailOutboxWorkerRepository($this->pdo);
        $otherPdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        $second=new PdoEmailOutboxWorkerRepository($otherPdo);

        self::assertTrue($first->acquireWorkerLock());
        self::assertFalse($second->acquireWorkerLock());
        $first->releaseWorkerLock();
        self::assertTrue($second->acquireWorkerLock());
        $second->releaseWorkerLock();
    }

    public function testImmediateDispatcherAndWorkerCannotBothClaimPendingRow(): void
    {
        $now=$this->now();
        $id=$this->insert('booking_request_received','pending',0,$now);
        $worker=new PdoEmailOutboxWorkerRepository($this->pdo);
        $item=$worker->claimNextDue($now,['booking_request_received']);
        self::assertSame($id,$item?->id);

        $otherPdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        self::assertNull((new PdoBookingRequestOutbox($otherPdo))->findForDelivery($this->bookingId));
    }

    public function testUnknownTypeIsCountedButNeverMutated(): void
    {
        $now=$this->now();
        $id=$this->insert('future_unknown_type','pending',0,$now);
        $repository=new PdoEmailOutboxWorkerRepository($this->pdo);

        self::assertSame(1,$repository->countUnsupported($this->types));
        self::assertNull($repository->claimNextDue($now,$this->types));
        self::assertSame('pending',$this->row($id)['status']);
    }

    public function testDueComparisonIsIndependentOfDatabaseSessionTimezone(): void
    {
        $now=$this->now();
        $id=$this->insert('booking_confirmed','failed',1,$now->modify('-300 seconds'));
        $utcPdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        $utcPdo->exec("SET time_zone = '+00:00'");

        self::assertSame($id,(new PdoEmailOutboxWorkerRepository($utcPdo))->claimNextDue($now,$this->types)?->id);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-12 12:00:00',new DateTimeZone('Europe/Budapest'));
    }

    private function insert(string $type,string $status,int $attempts,DateTimeImmutable $updatedAt,string $recipient='worker@example.invalid'):int
    {
        $payload=json_encode(['booking_reference'=>'WORKER','arrival_date'=>'2042-01-01','departure_date'=>'2042-01-03','adults'=>2,'child_ages'=>[],'children'=>0,'total'=>'50000.00','currency'=>'HUF'],JSON_THROW_ON_ERROR);
        $statement=$this->pdo->prepare('INSERT INTO email_outbox
            (booking_id,message_type,deduplication_key,recipient,subject,payload,status,attempts,updated_at)
            VALUES(:booking_id,:type,:deduplication_key,:recipient,\'Subject\',:payload,:status,:attempts,FROM_UNIXTIME(:updated_epoch))');
        $statement->execute(['booking_id'=>$this->bookingId,'type'=>$type,'deduplication_key'=>bin2hex(random_bytes(8)),'recipient'=>$recipient,
            'payload'=>$payload,'status'=>$status,'attempts'=>$attempts,'updated_epoch'=>$updatedAt->getTimestamp()]);
        return(int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(int $id):array
    {
        $statement=$this->pdo->prepare('SELECT status,attempts,last_error,sent_at,updated_at FROM email_outbox WHERE id=:id');
        $statement->execute(['id'=>$id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
