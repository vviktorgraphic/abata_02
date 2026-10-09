<?php
declare(strict_types=1);
namespace Tests\Unit\Mail;

use App\Application\Mail\BookingModificationMailData;
use App\Application\Mail\BookingModificationMailRenderer;
use App\Application\Mail\BookingModificationNotificationDispatcher;
use App\Application\Mail\BookingModificationOutbox;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use PHPUnit\Framework\TestCase;

final class BookingModificationNotificationTest extends TestCase
{
    public function testRendersApprovedSubjectDataDepositAndMailIdentity(): void
    {
        $message = $this->renderer()->render($this->data());
        self::assertSame('Foglalásának adatai módosultak', $message->subject);
        foreach (['Tisztelt <Vendég>!','Foglalását kérésének megfelelően módosítottuk.','2026-11-10','2026-11-13','2 felnőtt, 1 gyermek','Módosított szállásdíj:','90 000 Ft','Idegenforgalmi adó:','6 000 Ft, mely a szálláshelyen külön fizetendő','Módosított végösszeg:','96 000 Ft','Korábban megfizetett előleg:','45 000 Ft','A korábban megfizetett előleg összege a módosítás miatt nem változik.','Petróczki-Oravecz Anikó','tulajdonos-üzemeltető','A BATA'] as $copy) {
            self::assertStringContainsString($copy, $message->textBody);
        }
        self::assertSame('A Bata', $message->fromName);
        self::assertSame('info@abata.test', $message->replyToEmail);
        self::assertStringContainsString('&lt;Vendég&gt;', $message->htmlBody);
    }

    public function testDispatchIsRetryableAndDoesNotDuplicateSuccessfulDelivery(): void
    {
        $outbox = new FakeModificationOutbox();
        $mailer = new InMemoryMailer();
        $dispatcher = new BookingModificationNotificationDispatcher($outbox, $this->renderer(), $mailer);
        self::assertSame('sent', $dispatcher->dispatch(8)->status);
        self::assertSame('sent', $dispatcher->dispatch(8)->status);
        self::assertCount(1, $mailer->messages());
        self::assertSame([12], $outbox->sent);
    }

    public function testTransportFailureMarksSafeRetryableFailure(): void
    {
        $outbox = new FakeModificationOutbox();
        $mailer = new class implements Mailer { public function send(Message $message): void { throw new \RuntimeException('secret'); } };
        $result = (new BookingModificationNotificationDispatcher($outbox, $this->renderer(), $mailer))->dispatch(8);
        self::assertSame('failed', $result->status);
        self::assertSame([[12,'E-mail transport failure.']], $outbox->failed);
    }

    private function renderer(): BookingModificationMailRenderer
    {
        return new BookingModificationMailRenderer(dirname(__DIR__,3).'/templates/email','noreply@example.test','A Bata','info@abata.test','A Bata');
    }
    private function data(): BookingModificationMailData
    {
        return new BookingModificationMailData('guest@example.test','<Vendég>','AB-1','2026-11-10','2026-11-13',3,2,[3],'90000.00','6000.00','96000.00','45000.00','HUF');
    }
}

final class FakeModificationOutbox implements BookingModificationOutbox
{
    public bool $available = true;
    /** @var list<int> */ public array $sent=[];
    /** @var list<array{int,string}> */ public array $failed=[];
    public function findForDelivery(int $modificationId): ?array
    {
        if (!$this->available) return null;
        $this->available=false;
        return ['id'=>12,'booking_id'=>4,'data'=>new BookingModificationMailData('guest@example.test','Vendég','AB-1','2026-11-10','2026-11-13',3,2,[3],'90000.00','6000.00','96000.00','45000.00','HUF')];
    }
    public function markSent(int $outboxId): void { $this->sent[]=$outboxId; }
    public function markFailed(int $outboxId,string $safeReason): void { $this->failed[]=[$outboxId,$safeReason]; }
    public function status(int $modificationId): string { return $this->failed===[]?'sent':'failed'; }
}
