<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Mail\EmailOutboxRecoveredItem;
use App\Application\Mail\EmailOutboxRetryPolicy;
use App\Application\Mail\EmailOutboxWorkerRepository;
use App\Application\Mail\EmailOutboxWorkItem;
use DateTimeImmutable;
use PDO;

final readonly class PdoEmailOutboxWorkerRepository implements EmailOutboxWorkerRepository
{
    private const LOCK_NAME = 'abata_email_outbox_worker';
    private const STALE_REASON = 'Stale processing lease recovered.';
    private const TRANSPORT_REASON = 'E-mail transport failure.';
    private const PAYLOAD_REASON = 'Stored e-mail payload could not be rendered.';

    public function __construct(
        private PDO $pdo,
        private EmailOutboxRetryPolicy $policy = new EmailOutboxRetryPolicy(),
    ) {
    }

    public function acquireWorkerLock(): bool
    {
        return (int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() === 1;
    }

    public function releaseWorkerLock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')")->fetchColumn();
    }

    public function recoverStale(DateTimeImmutable $now, array $supportedTypes, int $limit): array
    {
        if ($limit < 1 || $supportedTypes === []) {
            return [];
        }
        $parameters = [];
        $in = $this->inClause($supportedTypes, 'stale_type_', $parameters);
        $parameters['stale_before_epoch'] = $now->getTimestamp() - $this->policy->staleProcessingSeconds();
        $select = $this->pdo->prepare(
            "SELECT id, booking_id, message_type, attempts FROM email_outbox
             WHERE status = 'processing' AND UNIX_TIMESTAMP(updated_at) <= :stale_before_epoch AND message_type IN ($in)
             ORDER BY id LIMIT " . (int) $limit
        );
        $select->execute($parameters);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $update = $this->pdo->prepare(
            "UPDATE email_outbox
             SET status = 'failed', attempts = LEAST(attempts + 1, :max_attempts),
                 last_error = :reason, sent_at = NULL, updated_at = FROM_UNIXTIME(:now_epoch)
             WHERE id = :id AND status = 'processing' AND attempts = :attempts
               AND UNIX_TIMESTAMP(updated_at) <= :stale_before_epoch"
        );
        $recovered = [];
        foreach ($rows as $row) {
            $update->execute([
                'max_attempts' => $this->policy->maxAttempts(),
                'reason' => self::STALE_REASON,
                'now_epoch' => $now->getTimestamp(),
                'id' => (int) $row['id'],
                'attempts' => (int) $row['attempts'],
                'stale_before_epoch' => $parameters['stale_before_epoch'],
            ]);
            if ($update->rowCount() === 1) {
                $recovered[] = new EmailOutboxRecoveredItem(
                    (int) $row['id'],
                    (int) $row['booking_id'],
                    (string) $row['message_type'],
                    min((int) $row['attempts'] + 1, $this->policy->maxAttempts()),
                );
            }
        }

        return $recovered;
    }

    public function claimNextDue(DateTimeImmutable $now, array $supportedTypes): ?EmailOutboxWorkItem
    {
        if ($supportedTypes === []) {
            return null;
        }
        $parameters = [];
        $in = $this->inClause($supportedTypes, 'claim_type_', $parameters);
        foreach ([1, 2, 3, 4] as $attempt) {
            $delay = $this->policy->retryDelaySeconds($attempt);
            if ($delay === null) {
                throw new \LogicException('Retry policy is incomplete.');
            }
            $parameters['cutoff_' . $attempt] = $now->getTimestamp() - $delay;
        }
        $select = $this->pdo->prepare(
            "SELECT id, booking_id, message_type, recipient, subject, payload, status, attempts
             FROM email_outbox
             WHERE message_type IN ($in) AND (
                (status = 'pending' AND attempts = 0 AND message_type <> 'booking_modified')
                OR (status = 'failed' AND attempts = 1 AND UNIX_TIMESTAMP(updated_at) <= :cutoff_1)
                OR (status = 'failed' AND attempts = 2 AND UNIX_TIMESTAMP(updated_at) <= :cutoff_2)
                OR (status = 'failed' AND attempts = 3 AND UNIX_TIMESTAMP(updated_at) <= :cutoff_3)
                OR (status = 'failed' AND attempts = 4 AND UNIX_TIMESTAMP(updated_at) <= :cutoff_4)
             ) ORDER BY id LIMIT 1"
        );
        $select->execute($parameters);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $claim = $this->pdo->prepare(
            "UPDATE email_outbox SET status = 'processing', updated_at = FROM_UNIXTIME(:now_epoch)
             WHERE id = :id AND status = :expected_status AND attempts = :expected_attempts
               AND attempts < :max_attempts"
        );
        $claim->execute([
            'now_epoch' => $now->getTimestamp(),
            'id' => (int) $row['id'],
            'expected_status' => (string) $row['status'],
            'expected_attempts' => (int) $row['attempts'],
            'max_attempts' => $this->policy->maxAttempts(),
        ]);
        if ($claim->rowCount() !== 1) {
            return null;
        }

        return new EmailOutboxWorkItem(
            (int) $row['id'],
            (int) $row['booking_id'],
            (string) $row['message_type'],
            (string) $row['recipient'],
            (string) $row['subject'],
            (string) $row['payload'],
            (int) $row['attempts'],
        );
    }

    public function markSent(EmailOutboxWorkItem $item, DateTimeImmutable $now): bool
    {
        return $this->finalize(
            $item,
            "status = 'sent', attempts = attempts + 1, last_error = NULL,
             sent_at = FROM_UNIXTIME(:sent_epoch), updated_at = FROM_UNIXTIME(:updated_epoch)",
            $now,
        );
    }

    public function markTransportFailed(EmailOutboxWorkItem $item, DateTimeImmutable $now): bool
    {
        return $this->finalize(
            $item,
            "status = 'failed', attempts = attempts + 1, last_error = :reason,
             sent_at = NULL, updated_at = FROM_UNIXTIME(:updated_epoch)",
            $now,
            self::TRANSPORT_REASON,
        );
    }

    public function markPayloadFailed(EmailOutboxWorkItem $item, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE email_outbox SET status = 'failed', attempts = :max_attempts,
                 last_error = :reason, sent_at = NULL, updated_at = FROM_UNIXTIME(:now_epoch)
             WHERE id = :id AND status = 'processing' AND attempts = :expected_attempts"
        );
        $statement->execute([
            'max_attempts' => $this->policy->maxAttempts(),
            'reason' => self::PAYLOAD_REASON,
            'now_epoch' => $now->getTimestamp(),
            'id' => $item->id,
            'expected_attempts' => $item->attempts,
        ]);

        return $statement->rowCount() === 1;
    }

    public function countUnsupported(array $supportedTypes): int
    {
        $parameters = [];
        $in = $this->inClause($supportedTypes, 'unsupported_type_', $parameters);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM email_outbox
             WHERE status IN ('pending', 'failed', 'processing') AND message_type NOT IN ($in)"
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    private function finalize(
        EmailOutboxWorkItem $item,
        string $assignments,
        DateTimeImmutable $now,
        ?string $reason = null,
    ): bool {
        $statement = $this->pdo->prepare(
            "UPDATE email_outbox SET $assignments
             WHERE id = :id AND status = 'processing' AND attempts = :expected_attempts
               AND attempts < :max_attempts"
        );
        $parameters = [
            'updated_epoch' => $now->getTimestamp(),
            'id' => $item->id,
            'expected_attempts' => $item->attempts,
            'max_attempts' => $this->policy->maxAttempts(),
        ];
        if ($reason === null) {
            $parameters['sent_epoch'] = $now->getTimestamp();
        }
        if ($reason !== null) {
            $parameters['reason'] = $reason;
        }
        $statement->execute($parameters);

        return $statement->rowCount() === 1;
    }

    /** @param list<string> $values @param array<string, string> $parameters */
    private function inClause(array $values, string $prefix, array &$parameters): string
    {
        if ($values === []) {
            throw new \InvalidArgumentException('At least one message type is required.');
        }
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $name = $prefix . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $value;
        }

        return implode(', ', $placeholders);
    }
}
