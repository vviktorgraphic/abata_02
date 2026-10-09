<?php
declare(strict_types=1);
namespace App\Application\Mail;
interface BookingModificationOutbox
{
    /** @return array{id:int,booking_id:int,data:BookingModificationMailData}|null */
    public function findForDelivery(int $modificationId): ?array;
    public function markSent(int $outboxId): void;
    public function markFailed(int $outboxId, string $safeReason): void;
    public function status(int $modificationId): string;
}
