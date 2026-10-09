<?php

declare(strict_types=1);

namespace App\Application\Booking;

interface BookingLifecycleRepository
{
    /** @return list<array{id:int,booking_id:int,payload:array{recipient:string},retry:bool}> */
    public function claimReviewRequests(string $startDate, string $today): array;
    public function markReviewSent(int $outboxId): void;
    public function markReviewFailed(int $outboxId, string $safeReason): void;
    /** @return list<int> booking ids */
    public function completeDeparted(string $startDate, string $today): array;
}
