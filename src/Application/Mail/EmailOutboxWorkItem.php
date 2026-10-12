<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class EmailOutboxWorkItem
{
    public function __construct(
        public int $id,
        public int $bookingId,
        public string $messageType,
        public string $recipient,
        public string $subject,
        public string $payload,
        public int $attempts,
    ) {
    }
}
