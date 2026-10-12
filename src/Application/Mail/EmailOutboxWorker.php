<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class EmailOutboxWorker
{
    public const BATCH_LIMIT = 25;

    /** @param null|\Closure():DateTimeImmutable $clock */
    public function __construct(
        private EmailOutboxWorkerRepository $repository,
        private EmailOutboxMessageFactory $messageFactory,
        private Mailer $mailer,
        private ?AuditLog $auditLog = null,
        private ?\Closure $clock = null,
    ) {
    }

    /** @return array{stale_recovered:int,claimed:int,sent:int,failed:int,final_failed:int,unsupported:int} */
    public function run(): array
    {
        $summary = $this->emptySummary();
        if (!$this->repository->acquireWorkerLock()) {
            return $summary;
        }

        try {
            $now = $this->now();
            $types = $this->messageFactory->supportedTypes();
            $recovered = $this->repository->recoverStale($now, $types, self::BATCH_LIMIT);
            $summary['stale_recovered'] = count($recovered);
            foreach ($recovered as $item) {
                if ($item->attempts >= EmailOutboxRetryPolicy::MAX_ATTEMPTS) {
                    ++$summary['final_failed'];
                }
                $this->audit('email.outbox_stale_recovered', 'failed', $item->bookingId, $item->id, $item->messageType, $item->attempts, $now);
            }

            $remaining = self::BATCH_LIMIT - count($recovered);
            while ($remaining-- > 0) {
                $item = $this->repository->claimNextDue($now, $types);
                if ($item === null) {
                    break;
                }
                ++$summary['claimed'];

                try {
                    $message = $this->messageFactory->create($item);
                } catch (Throwable) {
                    if (!$this->repository->markPayloadFailed($item, $now)) {
                        throw new \RuntimeException('Claimed outbox payload failure could not be finalized.');
                    }
                    ++$summary['failed'];
                    ++$summary['final_failed'];
                    $this->audit('email.outbox_payload_failed', 'failed', $item->bookingId, $item->id, $item->messageType, EmailOutboxRetryPolicy::MAX_ATTEMPTS, $now);
                    continue;
                }

                try {
                    $this->mailer->send($message);
                } catch (Throwable) {
                    if (!$this->repository->markTransportFailed($item, $now)) {
                        throw new \RuntimeException('Claimed outbox transport failure could not be finalized.');
                    }
                    $attempt = $item->attempts + 1;
                    ++$summary['failed'];
                    if ($attempt >= EmailOutboxRetryPolicy::MAX_ATTEMPTS) {
                        ++$summary['final_failed'];
                    }
                    $this->audit('email.outbox_retry_failed', 'failed', $item->bookingId, $item->id, $item->messageType, $attempt, $now);
                    continue;
                }

                if (!$this->repository->markSent($item, $now)) {
                    throw new \RuntimeException('Claimed outbox row could not be finalized after SMTP acceptance.');
                }
                ++$summary['sent'];
                $this->audit('email.outbox_retry_sent', 'sent', $item->bookingId, $item->id, $item->messageType, $item->attempts + 1, $now);
            }
            $summary['unsupported'] = $this->repository->countUnsupported($types);

            return $summary;
        } finally {
            $this->repository->releaseWorkerLock();
        }
    }

    /** @return array{stale_recovered:int,claimed:int,sent:int,failed:int,final_failed:int,unsupported:int} */
    private function emptySummary(): array
    {
        return ['stale_recovered' => 0, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'final_failed' => 0, 'unsupported' => 0];
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null
            ? new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'))
            : ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new \LogicException('E-mail outbox clock must return DateTimeImmutable.');
        }

        return $now->setTimezone(new DateTimeZone('Europe/Budapest'));
    }

    private function audit(
        string $event,
        string $result,
        int $bookingId,
        int $outboxId,
        string $messageType,
        int $attempt,
        DateTimeImmutable $now,
    ): void {
        if ($this->auditLog === null) {
            return;
        }
        try {
            $this->auditLog->append(new AuditEvent(
                $event,
                $result,
                $now,
                new AuditMetadata([
                    'target_type' => 'booking',
                    'target_id' => (string) $bookingId,
                    'outbox_id' => $outboxId,
                    'message_type' => $messageType,
                    'attempt_number' => $attempt,
                ]),
            ));
        } catch (Throwable) {
            // Delivery state is authoritative; an audit outage must not make accepted mail retryable.
        }
    }
}
