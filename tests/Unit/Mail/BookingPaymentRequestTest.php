<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Mail\BookingPaymentRequestConfiguration;
use App\Application\Mail\BookingPaymentRequestDispatcher;
use App\Application\Mail\BookingPaymentRequestMailData;
use App\Application\Mail\BookingPaymentRequestMailRenderer;
use App\Application\Mail\BookingPaymentRequestOutbox;
use App\Application\Mail\InMemoryMailer;
use App\Application\Mail\Mailer;
use App\Application\Mail\Message;
use PHPUnit\Framework\TestCase;

final class BookingPaymentRequestTest extends TestCase
{
    public function testAdvanceUsesCompleteTotalAndWholeHufHalfUp(): void
    {
        $config = new BookingPaymentRequestConfiguration('', '');
        self::assertSame('63000.00', $config->advanceFor('126000.00'));
        self::assertSame('63001.00', $config->advanceFor('126001.00'));
        self::assertSame('0.00', $config->advanceFor('0.00'));
        self::assertSame('5000000000.00', $config->advanceFor('9999999999.99'));
        self::assertSame('31500.00', (new BookingPaymentRequestConfiguration('', '', 25))->advanceFor('126000.00'));
    }

    public function testInvalidPercentageAndMissingBankConfigurationAreRejected(): void
    {
        foreach ([0, 101, -1] as $percent) {
            try {
                (new BookingPaymentRequestConfiguration('Test', 'Test', $percent))->assertConfigured();
                self::fail('Invalid percentage accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('ADVANCE_PERCENT', $error->getMessage());
            }
        }
        foreach ([['', ''], ['Test', ''], ['<placeholder>', 'Test']] as [$name, $account]) {
            try {
                (new BookingPaymentRequestConfiguration($name, $account))->assertConfigured();
                self::fail('Missing bank configuration accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('BANK_ACCOUNT', $error->getMessage());
            }
        }
    }

    public function testRendersFrozenPayloadBothFormatsAndEscapesHtml(): void
    {
        $data = $this->data();
        self::assertEquals($data, BookingPaymentRequestMailData::fromPayload($data->payload()));
        $message = $this->renderer()->render($data);
        self::assertSame('guest@example.test', $message->to);
        self::assertStringContainsString('előlegfizetés a foglalás véglegesítéséhez – TEST-42', $message->subject);
        foreach ([$message->textBody, $message->htmlBody] as $body) {
            foreach (['126 001 Ft', '63 001 Ft', '50%', 'Közlemény: TEST-42', 'TEST-ACCOUNT', '2040-01-01', '2040-01-04', 'előleg jóváírását'] as $text) {
                self::assertStringContainsString($text, $body);
            }
        }
        self::assertStringContainsString('&lt;Guest&gt;', $message->htmlBody);
        self::assertStringNotContainsString('<Guest>', $message->htmlBody);
    }

    public function testFailureRetryAndDoubleClickPreservePayloadAndAudit(): void
    {
        $outbox = new PaymentTestOutbox($this->data());
        $audit = new PaymentTestAudit();
        $mailer = new class implements Mailer {
            public bool $fail = true;
            public array $messages = [];
            public function send(Message $message): void
            {
                if ($this->fail) { throw new \RuntimeException('private transport detail'); }
                $this->messages[] = $message;
            }
        };
        $dispatcher = new BookingPaymentRequestDispatcher($outbox, $this->renderer(), $mailer,
            new BookingPaymentRequestConfiguration('new beneficiary', 'new account', 90), $audit);
        self::assertSame('failed', $dispatcher->dispatch('TEST-42', 7)->status);
        self::assertSame('E-mail transport failure.', $outbox->error);
        $mailer->fail = false;
        self::assertSame('sent', $dispatcher->dispatch('TEST-42', 7)->status);
        self::assertSame('sent', $dispatcher->dispatch('TEST-42', 7)->status);
        self::assertCount(1, $mailer->messages);
        self::assertStringContainsString('63 001 Ft', $mailer->messages[0]->textBody);
        self::assertSame(['email.payment_request_failed', 'email.payment_request_retry', 'email.payment_request_sent'],
            array_map(static fn (AuditEvent $event): string => $event->eventType, $audit->events));
        self::assertSame(['target_type' => 'booking', 'target_id' => '42', 'outbox_id' => 1], $audit->events[0]->metadata->values);
    }

    public function testAuditFailureAfterSentNeverMakesEmailRetryable(): void
    {
        $outbox = new PaymentTestOutbox($this->data());
        $audit = new class implements AuditLog {
            public function append(AuditEvent $event): void { throw new \RuntimeException('audit failed'); }
        };
        $dispatcher = new BookingPaymentRequestDispatcher($outbox, $this->renderer(), new InMemoryMailer(),
            new BookingPaymentRequestConfiguration('', ''), $audit);
        try { $dispatcher->dispatch('TEST-42'); self::fail('Audit failure expected.'); }
        catch (\RuntimeException) { self::assertSame('sent', $outbox->state); }
        self::assertSame('sent', $dispatcher->dispatch('TEST-42')->status);
    }

    private function data(): BookingPaymentRequestMailData
    {
        return new BookingPaymentRequestMailData('TEST-42', 'guest@example.test', '<Guest>', '2040-01-01',
            '2040-01-04', 'HUF', '126001.00', 50, '63001.00', 'Test beneficiary', 'TEST-ACCOUNT');
    }

    private function renderer(): BookingPaymentRequestMailRenderer
    {
        return new BookingPaymentRequestMailRenderer(dirname(__DIR__, 3) . '/templates/email', 'noreply@example.test');
    }
}

final class PaymentTestAudit implements AuditLog
{
    public array $events = [];
    public function append(AuditEvent $event): void { $this->events[] = $event; }
}

final class PaymentTestOutbox implements BookingPaymentRequestOutbox
{
    public string $state = 'pending';
    public ?string $error = null;
    public function __construct(private BookingPaymentRequestMailData $data) {}
    public function claim(string $reference, BookingPaymentRequestConfiguration $configuration): ?array
    {
        if ($this->state === 'sent') { return null; }
        $retry = $this->state === 'failed';
        $this->state = 'processing';
        return ['id' => 1, 'booking_id' => 42, 'data' => $this->data, 'retry' => $retry];
    }
    public function status(string $reference): string { return $this->state; }
    public function markSent(int $outboxId): void { $this->state = 'sent'; }
    public function markFailed(int $outboxId, string $safeReason): void { $this->state = 'failed'; $this->error = $safeReason; }
}
