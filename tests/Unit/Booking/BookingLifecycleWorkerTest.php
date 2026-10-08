<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Application\Booking\BookingLifecycleRepository;
use App\Application\Booking\BookingLifecycleWorker;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Application\Mail\InMemoryMailer;
use PHPUnit\Framework\TestCase;

final class BookingLifecycleWorkerTest extends TestCase
{
    public function testUsesExactBudapestTodayThenExactYesterdayAndIsIdempotent(): void
    {
        $repository=new LifecycleTestRepository(); $mailer=new InMemoryMailer();
        $worker=new BookingLifecycleWorker($repository,new BookingReviewMailRenderer(
            dirname(__DIR__,3).'/templates/email', 'from@example.test', 'A Bata',
            'info@abata.test', 'A Bata',
        ),$mailer,null,
            static fn()=>new \DateTimeImmutable('2026-10-08 00:15:00',new \DateTimeZone('Europe/Budapest')));
        self::assertSame(['review_sent'=>1,'review_failed'=>0,'completed'=>1],$worker->run());
        self::assertSame(['review_sent'=>0,'review_failed'=>0,'completed'=>0],$worker->run());
        self::assertSame(['2026-10-08','2026-10-08'],$repository->reviewDates);
        self::assertSame(['2026-10-07','2026-10-07'],$repository->completionDates);
        self::assertCount(1,$mailer->messages());
        self::assertSame('Értékelés',$mailer->lastMessage()->subject);
        self::assertSame('A Bata', $mailer->lastMessage()->fromName);
        self::assertSame('info@abata.test', $mailer->lastMessage()->replyToEmail);
        self::assertSame('A Bata', $mailer->lastMessage()->replyToName);
    }
}

final class LifecycleTestRepository implements BookingLifecycleRepository
{
    public array $reviewDates=[]; public array $completionDates=[]; private bool $done=false;
    public function claimReviewRequests(string $today):array{$this->reviewDates[]=$today;if($this->done)return[];return[['id'=>1,'booking_id'=>9,'payload'=>['recipient'=>'guest@example.test'],'retry'=>false]];}
    public function markReviewSent(int $outboxId):void{$this->done=true;}
    public function markReviewFailed(int $outboxId,string $safeReason):void{}
    public function completeDeparted(string $yesterday):array{$this->completionDates[]=$yesterday;return count($this->completionDates)===1?[9]:[];}
}
