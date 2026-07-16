<?php

declare(strict_types=1);

namespace App\Application\Audit;

final readonly class AuditEvent
{
    public function __construct(
        public string $eventType,
        public string $result,
        public \DateTimeImmutable $occurredAt,
        public AuditMetadata $metadata,
        public ?int $adminId = null,
        public ?string $ipHash = null,
        public ?string $userAgentSummary = null,
    ) {
        if ($eventType === '' || $result === '') {
            throw new \InvalidArgumentException('Audit event type and result are required.');
        }
        if ($ipHash !== null && !preg_match('/^[a-f0-9]{64}$/', $ipHash)) {
            throw new \InvalidArgumentException('Audit IP must be a SHA-256 hash.');
        }
    }
}
