<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface EmailOutboxWorkerRepository
{
    public function acquireWorkerLock(): bool;

    public function releaseWorkerLock(): void;

    /** @param list<string> $supportedTypes @return list<EmailOutboxRecoveredItem> */
    public function recoverStale(\DateTimeImmutable $now, array $supportedTypes, int $limit): array;

    /** @param list<string> $supportedTypes */
    public function claimNextDue(\DateTimeImmutable $now, array $supportedTypes): ?EmailOutboxWorkItem;

    public function markSent(EmailOutboxWorkItem $item, \DateTimeImmutable $now): bool;

    public function markTransportFailed(EmailOutboxWorkItem $item, \DateTimeImmutable $now): bool;

    public function markPayloadFailed(EmailOutboxWorkItem $item, \DateTimeImmutable $now): bool;

    /** @param list<string> $supportedTypes */
    public function countUnsupported(array $supportedTypes): int;
}
