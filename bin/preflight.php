<?php

declare(strict_types=1);

/** Validate a release before switching the webroot; never prints secret values. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
$failures = 0;
$ok = static function (string $name): void { printf("[OK] %s%s", $name, PHP_EOL); };
$fail = static function (string $name, string $message) use (&$failures): void {
    $failures++;
    printf("[FAIL] %s: %s%s", $name, $message, PHP_EOL);
};
$check = static function (string $name, callable $callback) use ($ok, $fail): void {
    try {
        $callback();
        $ok($name);
    } catch (Throwable $exception) {
        $fail($name, $exception->getMessage());
    }
};

try {
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php is missing.');
    }
    require $autoload;
    App\Bootstrap\EnvironmentBootstrap::load($root);
    $ok('environment bootstrap');
} catch (Throwable) {
    $fail('environment bootstrap', 'release bootstrap failed.');
    fwrite(STDOUT, "Preflight failed.\n");
    exit(1);
}

$check('database configuration', static function () use ($root): void {
    require $root . '/config/database.php';
});
$check('HTTP security configuration', static function () use ($root): void {
    require $root . '/config/http-security.php';
});
$check('legal document URLs', static function () use ($root): void {
    require $root . '/config/privacy-policy.php';
    require $root . '/config/booking-policy.php';
    require $root . '/config/house-rules.php';
});
$check('booking notification configuration', static function () use ($root): void { require $root . '/config/booking-notifications.php'; });
$check('admin session configuration', static function () use ($root): void {
    $config = require $root . '/config/auth.php';
    if (($config['session_idle_timeout_seconds'] ?? 0) < 1800
        || ($config['session_absolute_timeout_seconds'] ?? 0) <= $config['session_idle_timeout_seconds']) {
        throw new RuntimeException('Admin session idle/absolute timeout relationship is invalid.');
    }
    if (($config['rate_limit_pepper'] ?? '') === '' || str_contains((string) $config['rate_limit_pepper'], '<')) {
        throw new RuntimeException('AUTH_RATE_LIMIT_PEPPER is required.');
    }
    if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') === 'production' && !$config['cookie_secure']) {
        throw new RuntimeException('SESSION_COOKIE_SECURE=true is required in production.');
    }
});
$check('rate-limit configuration', static function (): void {
    foreach (['AUTH_LOGIN_IP_LIMIT', 'AUTH_LOGIN_ACCOUNT_LIMIT', 'AUTH_LOGIN_WINDOW_SECONDS', 'AUTH_LOCKOUT_SECONDS'] as $name) {
        $value = getenv($name);
        if ($value === false || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException($name . ' must be a positive integer.');
        }
    }
});
$check('SMTP configuration', static function () use ($root): void {
    $config = require $root . '/config/mail.php';
    new App\Infrastructure\Mail\SmtpConfiguration(
        $config['host'], $config['port'], $config['encryption'],
        $config['username'] === '' ? null : $config['username'],
        $config['password'] === '' ? null : $config['password'],
        $config['timeout_seconds'], $config['production'],
    );
});
$check('payment request configuration', static function () use ($root): void {
    $config=require $root.'/config/payment-request.php';
    (new App\Application\Mail\BookingPaymentRequestConfiguration($config['beneficiary'],$config['bank_account'],
        $config['advance_percent'],$config['bank_name'],$config['swift_bic']))->assertConfigured();
});
$check('arrival e-mail safe images', static function () use ($root): void {
    foreach(['bata1.jpg','bata2.jpg','bata3-safe.jpg','bata4.jpg'] as $file){
        $path=$root.'/resources/email/arrival/'.$file;
        $bytes=is_file($path)?file_get_contents($path):false;
        if(!is_string($bytes)||!str_starts_with($bytes,"\xFF\xD8"))throw new RuntimeException($file.' is missing or is not JPEG.');
    }
    if(is_file($root.'/resources/email/arrival/bata3.jpg'))throw new RuntimeException('Unsafe keybox source image must not be packaged.');
});
$check('static runtime files', static function () use ($root): void {
    foreach (['public/static/css/admin.css', 'public/static/css/booking.css', 'public/static/js/admin-auth.js', 'public/static/js/booking-calendar.js'] as $relative) {
        if (!is_file($root . '/' . $relative) || !is_readable($root . '/' . $relative)) {
            throw new RuntimeException($relative . ' is missing or unreadable.');
        }
    }
});

if (getenv('PREFLIGHT_SKIP_DB') !== '1') {
    $check('database connection', static function () use ($root): void {
        $pdo = App\Infrastructure\Database\ConnectionFactory::create(require $root . '/config/database.php');
        $pdo->query('SELECT 1')->fetchColumn();
    });
} else {
    fwrite(STDOUT, "[SKIP] database connection (PREFLIGHT_SKIP_DB=1)\n");
}

if ($failures > 0) {
    fwrite(STDOUT, "Preflight failed.\n");
    exit(1);
}
fwrite(STDOUT, "Preflight passed.\n");
