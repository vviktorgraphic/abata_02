<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Mail\BookingManualCommunicationDispatcher;
use App\Application\Mail\BookingManualCommunicationOutbox;
use App\Application\Mail\BookingManualCommunicationRenderer;
use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\InMemoryMailer;
use PHPUnit\Framework\TestCase;

final class BookingManualCommunicationTest extends TestCase
{
    public function testReminderUsesFrozenPaymentSnapshotAndCannotDuplicate(): void
    {
        $payload=['recipient'=>'guest@example.test','contact_name'=>'Teszt Vendég','advance_amount'=>'31000.00',
            'payment_reference'=>'AB-000123','bank_name'=>'Erste Bank','beneficiary'=>'Petróczki-Oravecz Anikó',
            'bank_account'=>'HU08 TEST','swift_bic'=>'GIBAHUHB'];
        $outbox=new ManualTestOutbox('booking_payment_reminder',$payload);
        $mailer=new InMemoryMailer(); $audit=new ManualTestAudit();
        $dispatcher=new BookingManualCommunicationDispatcher($outbox,$this->renderer(),$mailer,$this->config(),$audit);
        self::assertSame('sent',$dispatcher->paymentReminder('BOOKING',7)->status);
        self::assertSame('sent',$dispatcher->paymentReminder('BOOKING',7)->status);
        self::assertCount(1,$mailer->messages());
        self::assertStringContainsString('31 000 Ft',$mailer->lastMessage()->textBody);
        self::assertStringContainsString('AB-000123',$mailer->lastMessage()->textBody);
        self::assertSame('A Bata', $mailer->lastMessage()->fromName);
        self::assertSame('info@abata.test', $mailer->lastMessage()->replyToEmail);
        self::assertSame('A Bata', $mailer->lastMessage()->replyToName);
        self::assertSame(['email.payment_reminder_sent'],array_map(fn(AuditEvent $e)=>$e->eventType,$audit->events));
    }

    public function testArrivalHasFourSafeInlineJpegsAndCidReferences(): void
    {
        $message=$this->renderer()->render('booking_arrival_information',['recipient'=>'guest@example.test','contact_name'=>'Vendég']);
        self::assertCount(4,$message->inlineAttachments);
        self::assertSame('A Bata', $message->fromName);
        self::assertSame('info@abata.test', $message->replyToEmail);
        self::assertSame('A Bata', $message->replyToName);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $message->htmlBody);
        $xpath = new \DOMXPath($document);
        self::assertCount(4, $xpath->query('//img'));
        foreach(['arrival-front','arrival-mailboxes','arrival-keybox-safe','arrival-inside'] as $cid) {
            self::assertStringContainsString('cid:'.$cid,$message->htmlBody);
            $images = $xpath->query('//img[@src="cid:' . $cid . '"]');
            self::assertCount(1, $images);
            $image = $images->item(0);
            self::assertInstanceOf(\DOMElement::class, $image);
            self::assertSame('600', $image->getAttribute('width'));
            $style = $image->getAttribute('style');
            foreach (['display:block', 'width:600px', 'max-width:100%', 'height:auto', 'margin:0 auto'] as $declaration) {
                self::assertStringContainsString($declaration, $style, $cid);
            }
        }
        self::assertStringNotContainsString('cid:',$message->textBody);
        self::assertSame(['bata1.jpg','bata2.jpg','bata3-safe.jpg','bata4.jpg'],array_map(fn($a)=>$a->filename,$message->inlineAttachments));
    }

    public function testArrivalFailsClosedWhenImagesAreMissing(): void
    {
        $renderer=new BookingManualCommunicationRenderer(
            dirname(__DIR__,3).'/templates/email', sys_get_temp_dir().'/missing-arrival-assets',
            'from@example.test', 'A Bata', 'info@abata.test', 'A Bata',
        );
        $this->expectException(\RuntimeException::class);
        $renderer->render('booking_arrival_information',['recipient'=>'guest@example.test']);
    }

    private function renderer(): BookingManualCommunicationRenderer
    {
        return new BookingManualCommunicationRenderer(
            dirname(__DIR__,3).'/templates/email', dirname(__DIR__,3).'/resources/email/arrival',
            'from@example.test', 'A Bata', 'info@abata.test', 'A Bata',
        );
    }
    private function config(): BookingPaymentRequestConfiguration
    { return new BookingPaymentRequestConfiguration('Petróczki-Oravecz Anikó','HU08 TEST',50,'Erste Bank','GIBAHUHB'); }
}

final class ManualTestOutbox implements BookingManualCommunicationOutbox
{
    public string $state='pending'; public function __construct(private string $type,private array $payload){}
    public function claim(string $reference,string $type,BookingPaymentRequestConfiguration $configuration):?array
    { if($this->state==='sent')return null;$retry=$this->state==='failed';$this->state='processing';return ['id'=>1,'booking_id'=>123,'type'=>$this->type,'payload'=>$this->payload,'retry'=>$retry]; }
    public function status(string $reference,string $type):string{return $this->state;}
    public function markSent(int $outboxId,string $type):void{$this->state='sent';}
    public function markFailed(int $outboxId,string $type,string $safeReason):void{$this->state='failed';}
}
final class ManualTestAudit implements AuditLog { public array $events=[]; public function append(AuditEvent $event):void{$this->events[]=$event;} }
