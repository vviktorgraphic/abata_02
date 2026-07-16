<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class OutboxDeliveryResult
{
    public function __construct(public string $status)
    {
        if (!in_array($status, ['pending', 'sent', 'failed', 'not_applicable'], true)) {
            throw new \InvalidArgumentException('Invalid e-mail delivery status.');
        }
    }
}
