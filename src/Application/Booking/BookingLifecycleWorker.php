<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Application\Mail\Mailer;

final readonly class BookingLifecycleWorker
{
    /** @param null|\Closure():\DateTimeImmutable $clock */
    public function __construct(
        private BookingLifecycleRepository $repository,
        private BookingReviewMailRenderer $renderer,
        private Mailer $mailer,
        private string $startDate,
        private ?AuditLog $auditLog = null,
        private ?\Closure $clock = null,
    ) {}

    /** @return array{review_sent:int,review_failed:int,completed:int} */
    public function run(): array
    {
        $now=$this->clock === null ? new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')) : ($this->clock)();
        if (!$now instanceof \DateTimeImmutable) throw new \LogicException('Lifecycle clock must return DateTimeImmutable.');
        $today=$now->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('Y-m-d');
        $sent=0; $failed=0;
        foreach ($this->repository->claimReviewRequests($this->startDate, $today) as $item) {
            if ($item['retry']) $this->audit('email.review_request_retry','pending',$item['booking_id'],$item['id']);
            try { $this->mailer->send($this->renderer->render($item['payload'])); }
            catch (\Throwable) {
                $this->repository->markReviewFailed($item['id'],'E-mail transport or rendering failure.');
                $this->audit('email.review_request_failed','failed',$item['booking_id'],$item['id']);
                ++$failed; continue;
            }
            $this->repository->markReviewSent($item['id']);
            $this->audit('email.review_request_sent','sent',$item['booking_id'],$item['id']);
            ++$sent;
        }
        $completed=$this->repository->completeDeparted($this->startDate, $today);
        return ['review_sent'=>$sent,'review_failed'=>$failed,'completed'=>count($completed)];
    }

    private function audit(string $type,string $outcome,int $bookingId,int $outboxId): void
    {
        $this->auditLog?->append(new AuditEvent($type,$outcome,new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')),
            new AuditMetadata(['target_type'=>'booking','target_id'=>(string)$bookingId,'outbox_id'=>$outboxId])));
    }
}
