<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface BookingStatusNotificationOutbox
{
    /** @return array{id: int, data: BookingStatusMailData}|null */
    public function findForDelivery(int $bookingId, string $status): ?array;

    public function markSent(int $outboxId): void;

    public function markFailed(int $outboxId, string $safeReason): void;

    public function status(int $bookingId, string $status): string;
}
