<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BookingPolicyConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('BOOKING_POLICY_URL');
        putenv('BOOKING_POLICY_VERSION');
    }

    #[DataProvider('acceptedUrls')]
    public function testAcceptsSafePolicyUrl(string $environment, string $url): void
    {
        putenv('APP_ENV=' . $environment);
        putenv('BOOKING_POLICY_URL=' . $url);
        putenv('BOOKING_POLICY_VERSION=v1');

        $config = require dirname(__DIR__, 3) . '/config/booking-policy.php';

        self::assertSame($url, $config['url']);
        self::assertSame('v1', $config['version']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedUrls(): iterable
    {
        yield 'relative' => ['production', '/booking-policy'];
        yield 'https' => ['production', 'https://example.test/booking-policy'];
        yield 'development http' => ['development', 'http://localhost:8080/booking-policy'];
    }

    public function testRejectsHttpInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('BOOKING_POLICY_URL=http://example.test/policy');
        putenv('BOOKING_POLICY_VERSION=v1');

        $this->expectException(RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/booking-policy.php';
    }

    public function testRequiresVersion(): void
    {
        putenv('APP_ENV=testing');
        putenv('BOOKING_POLICY_URL=/booking-policy');
        putenv('BOOKING_POLICY_VERSION');

        $this->expectException(RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/booking-policy.php';
    }
}
