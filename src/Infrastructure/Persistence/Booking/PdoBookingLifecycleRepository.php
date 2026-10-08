<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\BookingLifecycleRepository;
use PDO;

final readonly class PdoBookingLifecycleRepository implements BookingLifecycleRepository
{
    public function __construct(private PDO $pdo) {}

    public function claimReviewRequests(string $today): array
    {
        $this->date($today);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("SELECT id,guest_email FROM bookings WHERE status='confirmed' AND departure_date=:today FOR UPDATE");
            $q->execute(['today'=>$today]);
            $bookings=$q->fetchAll(PDO::FETCH_ASSOC);
            $items=[];
            foreach ($bookings as $booking) {
                $out=$this->pdo->prepare("SELECT id,status,payload FROM email_outbox WHERE booking_id=:id AND message_type='booking_review_request' FOR UPDATE");
                $out->execute(['id'=>$booking['id']]);
                $row=$out->fetch(PDO::FETCH_ASSOC);
                $retry=$row !== false && $row['status']==='failed';
                if ($row !== false && !in_array($row['status'],['pending','failed'],true)) continue;
                $payload=['recipient'=>(string)$booking['guest_email'],'template_version'=>1];
                if ($row === false) {
                    $insert=$this->pdo->prepare("INSERT INTO email_outbox (booking_id,message_type,recipient,subject,payload) VALUES (:id,'booking_review_request',:recipient,'Értékelés',:payload)");
                    $insert->execute(['id'=>$booking['id'],'recipient'=>$booking['guest_email'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
                    $outboxId=(int)$this->pdo->lastInsertId();
                } else { $outboxId=(int)$row['id']; $payload=json_decode((string)$row['payload'],true,512,JSON_THROW_ON_ERROR); }
                $claim=$this->pdo->prepare("UPDATE email_outbox SET status='processing' WHERE id=:id AND status IN ('pending','failed')");
                $claim->execute(['id'=>$outboxId]);
                if ($claim->rowCount()===1) $items[]=['id'=>$outboxId,'booking_id'=>(int)$booking['id'],'payload'=>$payload,'retry'=>$retry];
            }
            $this->pdo->commit(); return $items;
        } catch (\Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function markReviewSent(int $outboxId): void
    {
        $q=$this->pdo->prepare("UPDATE email_outbox SET status='sent',attempts=attempts+1,sent_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id=:id AND message_type='booking_review_request' AND status='processing'");$q->execute(['id'=>$outboxId]);
    }

    public function markReviewFailed(int $outboxId,string $safeReason): void
    {
        $q=$this->pdo->prepare("UPDATE email_outbox SET status='failed',attempts=attempts+1,sent_at=NULL,last_error=:reason WHERE id=:id AND message_type='booking_review_request' AND status='processing'");$q->execute(['id'=>$outboxId,'reason'=>mb_substr($safeReason,0,500)]);
    }

    public function completeDeparted(string $yesterday): array
    {
        $this->date($yesterday); $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("SELECT id FROM bookings WHERE status='confirmed' AND departure_date=:departure FOR UPDATE");$q->execute(['departure'=>$yesterday]);
            $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            foreach($ids as $id){
                $update=$this->pdo->prepare("UPDATE bookings SET status='completed' WHERE id=:id AND status='confirmed'");$update->execute(['id'=>$id]);
                if($update->rowCount()!==1)continue;
                $history=$this->pdo->prepare("INSERT INTO booking_status_history (booking_id,old_status,new_status,changed_by_admin_id,note) VALUES (:id,'confirmed','completed',NULL,'Automatic lifecycle completion')");$history->execute(['id'=>$id]);
                $audit=$this->pdo->prepare("INSERT INTO audit_logs (event_type,admin_id,target_type,target_id,outcome,metadata_json) VALUES ('booking.completed_auto',NULL,'booking',:target_id,'success',:metadata)");
                $audit->execute(['target_id'=>(string)$id,'metadata'=>json_encode(['departure_date'=>$yesterday],JSON_THROW_ON_ERROR)]);
            }
            $this->pdo->commit(); return $ids;
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function date(string $date): void
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/Budapest'));
        if($parsed===false||$parsed->format('Y-m-d')!==$date)throw new \InvalidArgumentException('Érvénytelen lifecycle dátum.');
    }
}
