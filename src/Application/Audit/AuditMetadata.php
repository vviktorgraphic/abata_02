<?php

declare(strict_types=1);

namespace App\Application\Audit;

final readonly class AuditMetadata
{
    /** @param array<string, scalar|null> $values */
    public function __construct(public array $values)
    {
    }
}
