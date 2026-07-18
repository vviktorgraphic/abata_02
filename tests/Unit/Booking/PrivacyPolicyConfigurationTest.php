<?php
declare(strict_types=1);
namespace Tests\Unit\Booking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivacyPolicyConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['APP_ENV', 'PRIVACY_POLICY_URL', 'PRIVACY_POLICY_VERSION'] as $name) putenv($name);
    }

    #[DataProvider('validUrls')]
    public function testAcceptsSafeSnapshotConfiguration(string $environment, string $url): void
    {
        putenv('APP_ENV=' . $environment);
        putenv('PRIVACY_POLICY_URL=' . $url);
        putenv('PRIVACY_POLICY_VERSION=privacy-v1');
        self::assertSame(['url' => $url, 'version' => 'privacy-v1'], require dirname(__DIR__, 3) . '/config/privacy-policy.php');
    }

    public static function validUrls(): iterable
    {
        yield 'relative' => ['production', '/adatkezelesi_tajekoztato'];
        yield 'https' => ['production', 'https://example.test/privacy'];
        yield 'development http' => ['development', 'http://localhost:8080/privacy'];
    }

    public function testRejectsProductionHttpUrl(): void
    {
        putenv('APP_ENV=production');
        putenv('PRIVACY_POLICY_URL=http://example.test/privacy');
        putenv('PRIVACY_POLICY_VERSION=privacy-v1');
        $this->expectException(RuntimeException::class);
        require dirname(__DIR__, 3) . '/config/privacy-policy.php';
    }
}
