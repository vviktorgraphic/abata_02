<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class LocalComposeEnvironmentContractTest extends TestCase
{
    /**
     * Curated application-container inventory. Service- and command-specific variables
     * are deliberately excluded instead of deriving this list from every .env key.
     *
     * @var list<string>
     */
    private const APP_ENVIRONMENT_KEYS = [
        'APP_ENV', 'APP_DEBUG', 'APP_TIMEZONE',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'MAIL_HOST', 'MAIL_PORT', 'MAIL_TIMEOUT_SECONDS', 'MAIL_ENCRYPTION',
        'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME',
        'GUEST_REPLY_TO_EMAIL', 'GUEST_REPLY_TO_NAME',
        'PAYMENT_REQUEST_BENEFICIARY', 'PAYMENT_REQUEST_BANK_ACCOUNT',
        'PAYMENT_REQUEST_BANK_NAME', 'PAYMENT_REQUEST_SWIFT_BIC',
        'PAYMENT_REQUEST_ADVANCE_PERCENT',
        'SESSION_COOKIE_SECURE', 'ADMIN_SESSION_IDLE_TIMEOUT_SECONDS',
        'ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS', 'HSTS_MAX_AGE_SECONDS',
        'TRUSTED_PROXY_IPS', 'AUTH_LOGIN_IP_LIMIT', 'AUTH_LOGIN_ACCOUNT_LIMIT',
        'AUTH_LOGIN_WINDOW_SECONDS', 'AUTH_LOCKOUT_SECONDS', 'AUTH_RATE_LIMIT_PEPPER',
        'BOOKING_BODY_MAX_BYTES', 'BOOKING_RATE_LIMIT', 'BOOKING_RATE_WINDOW_SECONDS',
        'BOOKING_RATE_LOCKOUT_SECONDS', 'BOOKING_TRUSTED_ORIGINS',
        'BOOKING_POLICY_URL', 'BOOKING_POLICY_VERSION', 'PRIVACY_POLICY_URL',
        'PRIVACY_POLICY_VERSION', 'BOOKING_NOTIFICATION_EMAIL',
        'BOOKING_ADMIN_BASE_URL', 'HOUSE_RULES_URL', 'BOOKING_LIFECYCLE_ENABLED',
        'BOOKING_LIFECYCLE_START_DATE', 'ICAL_TIMEOUT_SECONDS', 'ICAL_MAX_RETRIES',
        'ICAL_BACKOFF_SECONDS', 'ICAL_MISSING_GRACE_SECONDS',
    ];

    public function testComposeExplicitlyForwardsTheCuratedApplicationEnvironment(): void
    {
        $compose = $this->repositoryFile('docker-compose.yml');
        $app = $this->serviceBlock($compose, 'app', 'db');

        self::assertStringNotContainsString('env_file:', $app);
        foreach (self::APP_ENVIRONMENT_KEYS as $key) {
            self::assertMatchesRegularExpression(
                '/^      ' . preg_quote($key, '/') . ':\s/m',
                $app,
                $key . ' must be explicitly forwarded to the app service.',
            );
        }
    }

    public function testDatabaseRootPasswordRemainsDatabaseOnly(): void
    {
        $compose = $this->repositoryFile('docker-compose.yml');
        $app = $this->serviceBlock($compose, 'app', 'db');
        $db = $this->serviceBlock($compose, 'db', 'mailpit');

        self::assertStringNotContainsString('DB_ROOT_PASSWORD', $app);
        self::assertMatchesRegularExpression(
            '/^      MYSQL_ROOT_PASSWORD:\s+\$\{DB_ROOT_PASSWORD:/m',
            $db,
        );
    }

    public function testExampleHasSafeDeterministicDevelopmentValues(): void
    {
        $environment = $this->parseEnvironmentExample();

        self::assertSame('false', $environment['BOOKING_LIFECYCLE_ENABLED'] ?? null);
        self::assertSame('', $environment['BOOKING_LIFECYCLE_START_DATE'] ?? null);
        self::assertSame(
            'http://localhost:8080/admin/bookings',
            $environment['BOOKING_ADMIN_BASE_URL'] ?? null,
        );

        $expectedPaymentValues = [
            'PAYMENT_REQUEST_BENEFICIARY' => 'A Bata Development Test',
            'PAYMENT_REQUEST_BANK_ACCOUNT' => 'DEVELOPMENT-ONLY-NOT-A-REAL-ACCOUNT',
            'PAYMENT_REQUEST_BANK_NAME' => 'Development Test Bank',
            'PAYMENT_REQUEST_SWIFT_BIC' => 'DEVELOPMENT',
            'PAYMENT_REQUEST_ADVANCE_PERCENT' => '50',
        ];
        foreach ($expectedPaymentValues as $key => $expected) {
            self::assertSame($expected, $environment[$key] ?? null);
        }

        $example = $this->repositoryFile('.env.example');
        self::assertStringContainsString('Fake development-only payment data', $example);
        self::assertStringContainsString('Never use these values for a real transfer', $example);
    }

    private function repositoryFile(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($content);

        return $content;
    }

    private function serviceBlock(string $compose, string $service, string $nextService): string
    {
        $matched = preg_match(
            '/^  ' . preg_quote($service, '/') . ':\R(?<block>.*?)(?=^  '
                . preg_quote($nextService, '/') . ':)/ms',
            $compose,
            $matches,
        );
        self::assertSame(1, $matched, 'Unable to locate the ' . $service . ' Compose service.');

        return $matches['block'];
    }

    /** @return array<string, string> */
    private function parseEnvironmentExample(): array
    {
        $values = [];
        foreach (preg_split('/\R/', $this->repositoryFile('.env.example')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return $values;
    }
}
