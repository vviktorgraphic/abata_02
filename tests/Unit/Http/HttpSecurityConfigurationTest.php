<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\HttpSecurityConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpSecurityConfigurationTest extends TestCase
{
    public function testProductionRequiresExplicitPositiveHstsMaxAge(): void
    {
        foreach ([false, '', '0'] as $value) {
            try {
                HttpSecurityConfiguration::fromValues('production', $value, false);
                self::fail('Missing or disabled production HSTS was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidMaxAges')]
    public function testInvalidHstsMaxAgeFailsFast(string $value): void
    {
        $this->expectException(RuntimeException::class);
        HttpSecurityConfiguration::fromValues('development', $value, false);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMaxAges(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'fraction' => ['1.5'];
        yield 'text' => ['forever'];
    }

    #[DataProvider('invalidProxyLists')]
    public function testInvalidTrustedProxyEntryFailsTheWholeConfiguration(string $value): void
    {
        $this->expectException(RuntimeException::class);
        HttpSecurityConfiguration::fromValues('development', '0', $value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProxyLists(): iterable
    {
        yield 'hostname' => ['proxy.internal'];
        yield 'mixed valid and invalid' => ['10.0.0.1,not-an-ip'];
        yield 'empty list member' => ['10.0.0.1,,10.0.0.2'];
        yield 'duplicate' => ['10.0.0.1,10.0.0.1'];
    }

    public function testValidProxyListAndDevelopmentDefaultsArePreserved(): void
    {
        self::assertSame([
            'environment' => 'development',
            'hsts_max_age_seconds' => 0,
            'trusted_proxy_ips' => ['10.0.0.1', '2001:db8::1'],
        ], HttpSecurityConfiguration::fromValues('development', false, '10.0.0.1, 2001:db8::1'));
    }
}
