<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequest;

final readonly class AdminManualBookingPreviewSigner
{
    public function __construct(private string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('A preview aláírókulcsa kötelező.');
        }
    }

    public function sign(BookingCreateRequest $request, string $total, string $currency): string
    {
        return hash_hmac('sha256', $this->payload($request, $total, $currency), $this->secret);
    }

    public function verify(string $signature, BookingCreateRequest $request, string $total, string $currency): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals($this->sign($request, $total, $currency), $signature);
    }

    private function payload(BookingCreateRequest $request, string $total, string $currency): string
    {
        return json_encode([
            'request' => $request->canonicalPayload(),
            'idempotency_key' => $request->idempotencyKey,
            'previewed_total' => $total,
            'currency' => $currency,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
