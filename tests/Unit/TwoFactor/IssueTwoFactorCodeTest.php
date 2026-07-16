<?php

declare(strict_types=1);

namespace Tests\Unit\TwoFactor;

use App\Application\TwoFactor\IssueTwoFactorCode;
use App\Application\TwoFactor\TwoFactorCodeStore;
use App\Domain\TwoFactor\TwoFactorCode;
use App\Domain\TwoFactor\TwoFactorCodeGenerator;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class IssueTwoFactorCodeTest extends TestCase
{
    public function testReplacementInvalidatesPreviousCodeThroughAtomicStoreBoundary(): void
    {
        $clock = new ResendClock($this->time());
        $store = new InMemoryCodeStore();
        $service = new IssueTwoFactorCode($store, new TwoFactorCodeGenerator($clock), $clock);

        $first = $service->issue(7);
        $clock->set($this->time()->add(new DateInterval('PT60S')));
        $second = $service->issue(7);

        self::assertNotNull($first->code->invalidatedAt());
        self::assertNull($second->code->invalidatedAt());
        self::assertSame(2, $store->replacementCount);
        self::assertNotSame($first->code->codeHash(), $second->code->codeHash());
    }

    public function testResendBeforeSixtySecondsIsRejectedWithoutReplacement(): void
    {
        $clock = new ResendClock($this->time());
        $store = new InMemoryCodeStore();
        $service = new IssueTwoFactorCode($store, new TwoFactorCodeGenerator($clock), $clock);
        $service->issue(7);
        $clock->set($this->time()->add(new DateInterval('PT59S')));

        try {
            $service->issue(7);
            self::fail('Expected resend rejection.');
        } catch (DomainException) {
            self::assertSame(1, $store->replacementCount);
        }
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-16 12:00:00', new DateTimeZone('Europe/Budapest'));
    }
}

final class ResendClock implements \App\Domain\TwoFactor\TwoFactorClock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
    public function set(DateTimeImmutable $now): void { $this->now = $now; }
}

final class InMemoryCodeStore implements TwoFactorCodeStore
{
    public int $replacementCount = 0;
    private ?TwoFactorCode $active = null;
    private ?DateTimeImmutable $sentAt = null;

    public function replaceActiveIfAllowed(
        int $adminId,
        TwoFactorCode $code,
        DateTimeImmutable $sentAt,
        DateTimeImmutable $latestAllowedPreviousSend,
    ): bool
    {
        if ($this->sentAt !== null && $this->sentAt > $latestAllowedPreviousSend) {
            return false;
        }
        if ($this->active !== null) {
            $this->active->invalidate($sentAt);
        }
        $this->active = $code;
        $this->sentAt = $sentAt;
        ++$this->replacementCount;
        return true;
    }
}
