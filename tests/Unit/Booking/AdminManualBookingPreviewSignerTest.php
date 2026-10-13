<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Application\Booking\AdminManualBookingPreviewSigner;
use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Booking\BookingPeriod;
use App\Domain\Booking\BookingCreateRequest;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AdminManualBookingPreviewSignerTest extends TestCase
{
    public function testSignatureBindsCanonicalPayloadIdempotencyPriceAndCurrency(): void
    {
        $signer = new AdminManualBookingPreviewSigner('test-secret');
        $request = $this->request();
        $hash = str_repeat('a', 64);
        $signature = $signer->sign($request, '45000.00', $hash, '42000.00', '42000.00', 'HUF');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
        self::assertTrue($signer->verify($signature, $request, '45000.00', $hash, '42000.00', '42000.00', 'HUF'));
        self::assertFalse($signer->verify($signature, $request, '45001.00', $hash, '42000.00', '42000.00', 'HUF'));
        self::assertFalse($signer->verify($signature, $request, '45000.00', $hash, null, '42000.00', 'HUF'));
        self::assertFalse($signer->verify($signature, $request, '45000.00', $hash, '42000.00', '42001.00', 'HUF'));
        self::assertFalse($signer->verify($signature, $request, '45000.00', str_repeat('b', 64), '42000.00', '42000.00', 'HUF'));
        self::assertFalse($signer->verify($signature, $request, '45000.00', $hash, '42000.00', '42000.00', 'EUR'));
        self::assertFalse($signer->verify('invalid', $request, '45000.00', $hash, '42000.00', '42000.00', 'HUF'));

        $tampered = new BookingCreateRequest(
            $request->period,
            $request->contactName,
            'changed@example.invalid',
            $request->phone,
            $request->adults,
            $request->children,
            $request->childAges,
            $request->notes,
            false,
            false,
            false,
            $request->idempotencyKey,
        );
        self::assertFalse($signer->verify($signature, $tampered, '45000.00', $hash, '42000.00', '42000.00', 'HUF'));
    }

    public function testSignatureBindsIdempotencyKey(): void
    {
        $signer = new AdminManualBookingPreviewSigner('test-secret');
        $request = $this->request();
        $hash = str_repeat('a', 64);
        $signature = $signer->sign($request, '45000.00', $hash, null, '45000.00', 'HUF');
        $changedKey = new BookingCreateRequest(
            $request->period,
            $request->contactName,
            $request->email,
            $request->phone,
            $request->adults,
            $request->children,
            $request->childAges,
            $request->notes,
            false,
            false,
            false,
            'different-admin-key-123',
        );

        self::assertFalse($signer->verify($signature, $changedKey, '45000.00', $hash, null, '45000.00', 'HUF'));
    }

    private function request(): BookingCreateRequest
    {
        $zone = new DateTimeZone('Europe/Budapest');

        return new BookingCreateRequest(
            new BookingPeriod(new DateTimeImmutable('2047-06-10', $zone), new DateTimeImmutable('2047-06-12', $zone)),
            'Teszt Vendég',
            'guest@example.invalid',
            '+36301234567',
            2,
            1,
            [3],
            'Megjegyzés',
            false,
            false,
            false,
            'admin-test-key-123456',
        );
    }
}
