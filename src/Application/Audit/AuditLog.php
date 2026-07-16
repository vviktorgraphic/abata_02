<?php

declare(strict_types=1);

namespace App\Application\Audit;

/** Append-only audit persistence boundary. */
interface AuditLog
{
    public function append(AuditEvent $event): void;
}
