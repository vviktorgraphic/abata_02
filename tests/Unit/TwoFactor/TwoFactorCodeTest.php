<?php

declare(strict_types=1);

namespace Tests\Unit\TwoFactor;

use App\Domain\TwoFactor\TwoFactorClock;
use App\Domain\TwoFactor\TwoFactorCode;
use App\Domain\TwoFactor\TwoFactorCodeGenerator;
use App\Domain\TwoFactor\TwoFactorCodeRejected;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class TwoFactorCodeTest extends TestCase
{
    public function testGeneratedCodeHasSixDigitsAndOnlyItsHashIsInTheAggregate(): void
    {
        $generated = (new TwoFactorCodeGenerator(new FrozenClock($this->time())))->generate();

        self::assertMatchesRegularExpression('/^\d{6}$/D', $generated->plainCode);
        self::assertNotSame($generated->plainCode, $generated->code->codeHash());
        self::assertTrue(password_verify($generated->plainCode, $generated->code->codeHash()));
        self::assertFalse(property_exists($generated, 'plaintext'), 'The delivery boundary must use the DTO plainCode contract.');
        self::assertSame('2026-07-16 12:10:00', $generated->code->expiresAt()->format('Y-m-d H:i:s'));
    }

    public function testAdminDeliveryUsesTheGeneratedCodeContract(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3) . '/src/Http/Controller/Admin/DefaultAdminAuthWorkflow.php');

        self::assertIsString($workflow);
        self::assertStringNotContainsString('->plaintext', $workflow);
        self::assertSame(2, substr_count($workflow, '->plainCode'));
    }

    /** @dataProvider unusableCodeProvider */
    public function testUnusableCodeIsRejected(string $state, string $reason): void
    {
        $now = $this->time();
        $code = $this->code($now->add(new DateInterval('PT10M')));
        if ($state === 'used') {
            $code->verify('123456', $now);
        } elseif ($state === 'invalidated') {
            $code->invalidate($now);
        }

        try {
            $code->verify('123456', $state === 'expired' ? $now->add(new DateInterval('PT10M')) : $now);
            self::fail('Expected rejection.');
        } catch (TwoFactorCodeRejected $exception) {
            self::assertSame($reason, $exception->reason());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableCodeProvider(): iterable
    {
        yield 'expired' => ['expired', 'expired'];
        yield 'used' => ['used', 'used'];
        yield 'invalidated' => ['invalidated', 'invalidated'];
    }

    public function testFiveFailuresExhaustCodeAndCorrectSixthAttemptIsRejected(): void
    {
        $code = $this->code($this->time()->add(new DateInterval('PT10M')));
        for ($attempt = 0; $attempt < TwoFactorCode::MAX_ATTEMPTS; ++$attempt) {
            try {
                $code->verify('000000', $this->time());
            } catch (TwoFactorCodeRejected $exception) {
                self::assertSame('invalid', $exception->reason());
            }
        }

        self::assertSame(5, $code->attemptCount());
        $this->expectException(TwoFactorCodeRejected::class);
        $code->verify('123456', $this->time());
    }

    private function code(DateTimeImmutable $expiresAt): TwoFactorCode
    {
        return new TwoFactorCode((string) password_hash('123456', PASSWORD_DEFAULT), $expiresAt);
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-16 12:00:00', new DateTimeZone('Europe/Budapest'));
    }
}

final class FrozenClock implements TwoFactorClock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
    public function set(DateTimeImmutable $now): void { $this->now = $now; }
}
