<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface BookingManualCommunicationOutbox
{
    /** @return array{id:int,booking_id:int,type:string,payload:array<string,mixed>,retry:bool}|null */
    public function claim(string $reference, string $type, BookingPaymentRequestConfiguration $configuration): ?array;
    public function status(string $reference, string $type): string;
    public function markSent(int $outboxId, string $type): void;
    public function markFailed(int $outboxId, string $type, string $safeReason): void;
}
