<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Http\Controller\Admin\AdminView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingPaymentRequestUiTest extends TestCase
{
    #[DataProvider('pendingStates')]
    public function testPendingWorkflowRequiresSentRequestAndPreservesOtherActions(?string $state, string $message, bool $canSend, bool $canConfirm): void
    {
        $payment = $state === null ? null : ['status' => $state, 'advance_amount' => '63000.00', 'sent_at' => $state === 'sent' ? '2026-10-05 12:30:00' : null];
        $html = $this->render('pending', $payment);
        self::assertStringContainsString('Foglalás véglegesítése', $html);
        self::assertStringContainsString($message, $html);
        self::assertStringContainsString('Előleg: 63 000 Ft', $html);
        self::assertStringNotContainsString('63000.00', $html);
        $xpath = $this->xpath($html);
        foreach (['confirm' => $canConfirm, 'payment-request' => $canSend, 'reject' => true, 'invalidate' => true, 'cancel' => false] as $action => $available) {
            $forms = $xpath->query('//form[@action="/admin/bookings/AB-TEST/' . $action . '"]');
            self::assertSame($available ? 1 : 0, $forms->length, $action);
            foreach ($forms as $form) {
                self::assertSame('post', $form->getAttribute('method'));
                self::assertSame(1, $xpath->query('input[@name="_csrf" and @value="safe-token"]', $form)->length);
            }
        }
        if ($canConfirm) {
            self::assertStringContainsString('Díjbekérő elküldve: 2026-10-05 12:30:00', $html);
            self::assertStringContainsString('Az előleg beérkezésének ellenőrzése után', $html);
        } else {
            self::assertStringContainsString('A foglalás a díjbekérő sikeres elküldése után erősíthető meg.', $html);
        }
        if ($state !== null) self::assertStringContainsString('Díjbekérő —', $html);
    }

    public static function pendingStates(): iterable
    {
        yield 'not requested' => [null, 'Díjbekérő e-mail küldése', true, false];
        yield 'queued' => ['pending', 'Díjbekérő e-mail küldése', true, false];
        yield 'failed' => ['failed', 'Díjbekérő újraküldése', true, false];
        yield 'processing' => ['processing', 'Díjbekérő küldése folyamatban.', false, false];
        yield 'sent' => ['sent', 'Megerősítés', false, true];
    }

    public function testStoredAdvanceOverridesCurrentConfigurationPreview(): void
    {
        $html = $this->render('pending', ['status' => 'failed', 'advance_amount' => '40001.00', 'sent_at' => null]);
        self::assertStringContainsString('Előleg: 40 001 Ft', $html);
        self::assertStringNotContainsString('Előleg: 63 000 Ft', $html);
        self::assertStringContainsString('Díjbekérő küldése sikertelen', $html);
    }

    public function testConfirmedBookingKeepsCancellationWithoutAnotherPaymentRequest(): void
    {
        $html = $this->render('confirmed', ['status' => 'sent', 'advance_amount' => '63000.00', 'sent_at' => '2026-10-05 12:30:00']);
        self::assertStringNotContainsString('Foglalás véglegesítése', $html);
        self::assertStringNotContainsString('/payment-request"', $html);
        self::assertStringNotContainsString('/confirm"', $html);
        self::assertStringContainsString('/cancel"', $html);
        self::assertStringContainsString('/invalidate"', $html);
    }

    private function render(string $status, ?array $payment): string
    {
        return (new AdminView(dirname(__DIR__, 3) . '/templates'))->render('booking-detail', [
            'csrfToken' => 'safe-token',
            'paymentAdvance' => '63000.00',
            'booking' => [
                'reference' => 'AB-TEST', 'status' => $status, 'total_amount' => '126000.00',
                'pricing_snapshot' => [], 'status_history' => [], 'payment_request' => $payment,
                'email_outbox' => $payment === null ? [] : [['type' => 'booking_payment_request', 'status' => $payment['status'], 'attempts' => 1]],
            ],
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        return new \DOMXPath($document);
    }
}
