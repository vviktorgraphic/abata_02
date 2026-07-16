<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use DateTimeZone;
use JsonException;
use PDO;

final readonly class PdoAuditLog implements AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @throws JsonException */
    public function append(AuditEvent $event): void
    {
        $metadata = $event->metadata->values;
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (event_type, admin_id, target_type, target_id, outcome, correlation_id,
                 ip_hash, user_agent, metadata_json, created_at)
             VALUES
                (:event_type, :admin_id, :target_type, :target_id, :outcome, :correlation_id,
                 :ip_hash, :user_agent, :metadata_json, :created_at)'
        );
        $statement->execute([
            'event_type' => $event->eventType,
            'admin_id' => $event->adminId,
            'target_type' => $metadata['target_type'] ?? null,
            'target_id' => isset($metadata['target_id']) ? (string) $metadata['target_id'] : null,
            'outcome' => $event->result,
            'correlation_id' => $metadata['correlation_id'] ?? null,
            'ip_hash' => $event->ipHash,
            'user_agent' => $event->userAgentSummary,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $event->occurredAt
                ->setTimezone(new DateTimeZone('Europe/Budapest'))
                ->format('Y-m-d H:i:s'),
        ]);
    }
}
