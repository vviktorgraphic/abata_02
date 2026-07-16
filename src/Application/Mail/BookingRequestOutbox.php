<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface BookingRequestOutbox
{
    /** @return array{id: int, data: BookingRequestMailData}|null */
    public function findForDelivery(int $bookingId): ?array;

    public function markSent(int $outboxId): void;

    public function markFailed(int $outboxId, string $safeReason): void;

    public function statusForBooking(int $bookingId): string;
}
