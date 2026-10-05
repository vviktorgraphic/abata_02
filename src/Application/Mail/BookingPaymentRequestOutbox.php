<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface BookingPaymentRequestOutbox
{
    /** Atomically queue/claim a pending booking; return only after commit.
     * @return array{id: int, booking_id: int, data: BookingPaymentRequestMailData, retry: bool}|null
     */
    public function claim(string $reference, BookingPaymentRequestConfiguration $configuration): ?array;
    public function status(string $reference): string;
    public function markSent(int $outboxId): void;
    public function markFailed(int $outboxId, string $safeReason): void;
}
