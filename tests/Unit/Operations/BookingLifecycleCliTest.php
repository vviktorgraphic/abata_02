<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class BookingLifecycleCliTest extends TestCase
{
    public function testDisabledWorkerExitsSuccessfullyBeforeDatabaseOrSmtpInitialization(): void
    {
        $root = dirname(__DIR__, 3);
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['BOOKING_LIFECYCLE_ENABLED'] = 'false';
        $environment['BOOKING_LIFECYCLE_START_DATE'] = '';
        $environment['DB_HOST'] = 'must-not-connect.invalid';
        $environment['MAIL_HOST'] = 'must-not-connect.invalid';
        $process = proc_open(
            [PHP_BINARY, $root . '/bin/booking-lifecycle-worker.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, (string) $stderr);
        self::assertSame('', $stderr);
        self::assertSame([
            'event' => 'booking_lifecycle_disabled',
            'review_sent' => 0,
            'review_failed' => 0,
            'completed' => 0,
        ], json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR));
    }
}
