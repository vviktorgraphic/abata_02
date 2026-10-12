<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class EmailOutboxRecoveredItem
{
    public function __construct(
        public int $id,
        public int $bookingId,
        public string $messageType,
        public int $attempts,
    ) {
    }
}
