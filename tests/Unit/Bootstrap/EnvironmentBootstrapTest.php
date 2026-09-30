<?php

declare(strict_types=1);

namespace Tests\Unit\Bootstrap;

use App\Bootstrap\EnvironmentBootstrap;
use PHPUnit\Framework\TestCase;

final class EnvironmentBootstrapTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
            if ($value === false) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    public function testLoadsLfValuesAndPreservesEqualsWithoutOutput(): void
    {
        $this->remember('BOOTSTRAP_LF', 'BOOTSTRAP_SECRET');
        $root = $this->envDirectory("# comment\n\nBOOTSTRAP_LF=first=value\nmalformed\nBOOTSTRAP_SECRET=do-not-print\n");
        ob_start();
        EnvironmentBootstrap::load($root);
        $output = ob_get_clean();

        self::assertSame('', $output);
        self::assertSame('first=value', getenv('BOOTSTRAP_LF'));
        self::assertSame('first=value', $_ENV['BOOTSTRAP_LF']);
        self::assertSame('do-not-print', getenv('BOOTSTRAP_SECRET'));
        self::assertStringNotContainsString('do-not-print', $output);
    }

    public function testCrLfValuesLoadAndProcessEnvironmentWins(): void
    {
        $this->remember('BOOTSTRAP_CRLF', 'BOOTSTRAP_OVERRIDE');
        putenv('BOOTSTRAP_OVERRIDE=process-value');
        $_ENV['BOOTSTRAP_OVERRIDE'] = 'stale-value';
        $root = $this->envDirectory("BOOTSTRAP_CRLF=windows-value\r\nBOOTSTRAP_OVERRIDE=file-value\r\n");

        EnvironmentBootstrap::load($root);

        self::assertSame('windows-value', getenv('BOOTSTRAP_CRLF'));
        self::assertSame('process-value', getenv('BOOTSTRAP_OVERRIDE'));
        self::assertSame('process-value', $_ENV['BOOTSTRAP_OVERRIDE']);
    }

    public function testWebSecurityAndDatabaseConfigurationSeeBootstrappedValues(): void
    {
        foreach (['APP_ENV', 'HSTS_MAX_AGE_SECONDS', 'TRUSTED_PROXY_IPS', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
            $this->remember($name);
        }
        $root = $this->envDirectory("APP_ENV=production\nHSTS_MAX_AGE_SECONDS=300\nTRUSTED_PROXY_IPS=\nDB_HOST=db-from-env\nDB_PORT=3306\nDB_DATABASE=booking\nDB_USERNAME=user\nDB_PASSWORD=secret\n");

        EnvironmentBootstrap::load($root);
        $security = require dirname(__DIR__, 3) . '/config/http-security.php';
        $database = require dirname(__DIR__, 3) . '/config/database.php';

        self::assertSame('production', $security['environment']);
        self::assertSame(300, $security['hsts_max_age_seconds']);
        self::assertSame('db-from-env', $database['host']);
        self::assertSame(3306, $database['port']);
    }

    private function remember(string ...$names): void
    {
        foreach ($names as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    private function envDirectory(string $contents): string
    {
        $directory = sys_get_temp_dir() . '/env-bootstrap-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/.env', $contents);
        register_shutdown_function(static function () use ($directory): void {
            @unlink($directory . '/.env');
            @rmdir($directory);
        });

        return $directory;
    }
}
