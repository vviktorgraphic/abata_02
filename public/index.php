<?php

declare(strict_types=1);

use App\Http\Controller\HomeController;
use App\Http\Router;
use App\Application\Availability\GetAvailabilityHandler;
use App\Application\Booking\BookingOutboxDispatcher;
use App\Application\Booking\DefaultBookingCreateWorkflow;
use App\Application\Mail\BookingRequestMailRenderer;
use App\Application\Mail\BookingRequestOutboxDispatcher;
use App\Http\Controller\AvailabilityController;
use App\Http\Controller\BookingCreateController;
use App\Http\Controller\BookingValidationController;
use App\Http\SecurityRateLimiterAdapter;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Mail\SmtpConfiguration;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Persistence\PdoBlockedPeriodReadRepository;
use App\Infrastructure\Persistence\PdoBookingReadRepository;
use App\Infrastructure\Persistence\Auth\PdoRateLimitRepository;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Booking\PdoBookingRequestOutbox;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Pricing\PdoBookingPricingProvider;
use App\Security\RateLimit\RateLimitClock;
use App\Security\RateLimit\RateLimiter;
use App\Security\RateLimit\RateLimitPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Europe/Budapest');

$router = new Router();
$controller = new HomeController(dirname(__DIR__) . '/templates');

$router->get('/', [$controller, 'index']);
$router->get('/health', [$controller, 'health']);
$admin = static function (): array {
    static $controllers;
    return $controllers ??= require dirname(__DIR__) . '/config/admin-http.php';
};
$context = static fn (): array => ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')];
$router->get('/admin/login', static fn () => $admin()['login']->show()->send());
$router->post('/admin/login', static fn () => $admin()['login']->submit($_POST, $context())->send());
$router->get('/admin/2fa', static fn () => $admin()['two_factor']->show()->send());
$router->post('/admin/2fa/verify', static fn () => $admin()['two_factor']->verify($_POST, $context())->send());
$router->post('/admin/2fa/resend', static fn () => $admin()['two_factor']->resend($_POST, $context())->send());
$router->get('/admin', static fn () => $admin()['dashboard']->show()->send());
$router->post('/admin/logout', static fn () => $admin()['logout']->submit($_POST, $context())->send());
$router->get('/api/availability', static function (array $query): void {
    try {
        $root = dirname(__DIR__);
        $database = ConnectionFactory::create(require $root . '/config/database.php');
        $booking = require $root . '/config/booking.php';
        $handler = new GetAvailabilityHandler(
            new PdoBookingReadRepository($database, $booking['blocking_statuses']),
            new PdoBlockedPeriodReadRepository($database),
            $booking['availability_query_max_days'],
            $booking['minimum_nights'],
            $booking['maximum_nights'],
            $booking['booking_horizon_days'],
        );
        (new AvailabilityController($handler))->index($query);
    } catch (Throwable) {
        App\Http\JsonResponse::send(['error' => 'Availability is temporarily unavailable.'], 500);
    }
});
$router->post('/api/booking/validate', static function (): void {
    try {
        $input = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            App\Http\JsonResponse::send(['valid' => false, 'error' => 'Invalid request body.'], 422);
            return;
        }
        $root = dirname(__DIR__);
        $database = ConnectionFactory::create(require $root . '/config/database.php');
        $booking = require $root . '/config/booking.php';
        $handler = new GetAvailabilityHandler(
            new PdoBookingReadRepository($database, $booking['blocking_statuses']),
            new PdoBlockedPeriodReadRepository($database),
            $booking['availability_query_max_days'],
            $booking['minimum_nights'],
            $booking['maximum_nights'],
            $booking['booking_horizon_days'],
        );
        (new BookingValidationController($handler))->validate($input);
    } catch (Throwable) {
        App\Http\JsonResponse::send(['valid' => false, 'error' => 'Az ellenőrzés átmenetileg nem érhető el.'], 500);
    }
});
$router->post('/api/bookings', static function (): void {
    try {
        $root = dirname(__DIR__);
        $pdo = ConnectionFactory::create(require $root . '/config/database.php');
        $booking = require $root . '/config/booking.php';
        $mail = require $root . '/config/mail.php';
        $auth = require $root . '/config/auth.php';
        $pepper = (string) ($auth['rate_limit_pepper'] ?? '');
        if ($pepper === '') {
            throw new RuntimeException('AUTH_RATE_LIMIT_PEPPER is required.');
        }
        $clock = new class implements RateLimitClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'));
            }
        };
        $rateLimiter = new SecurityRateLimiterAdapter(
            new RateLimiter(new PdoRateLimitRepository($pdo), $clock, $pepper),
            new RateLimitPolicy(
                'booking_create_ip',
                $booking['create_rate_limit'],
                $booking['create_rate_window_seconds'],
                $booking['create_rate_lockout_seconds'],
            ),
        );
        $smtp = new SmtpMailer(new SmtpConfiguration(
            $mail['host'],
            $mail['port'],
            $mail['encryption'],
            $mail['username'] !== '' ? $mail['username'] : null,
            $mail['password'] !== '' ? $mail['password'] : null,
        ));
        $outbox = new BookingOutboxDispatcher(new BookingRequestOutboxDispatcher(
            new PdoBookingRequestOutbox($pdo),
            new BookingRequestMailRenderer($root . '/templates/email', $mail['from_email']),
            $smtp,
        ));
        $workflow = new DefaultBookingCreateWorkflow(
            new TransactionalBookingRepository($pdo),
            new PdoBookingPricingProvider(),
            $outbox,
        );
        $controller = new BookingCreateController(
            App\Domain\Booking\BookingCreateRequestValidator::forBudapestToday(
                $booking['minimum_nights'],
                $booking['maximum_nights'],
                $booking['booking_horizon_days'],
            ),
            $workflow,
            $rateLimiter,
            $booking['trusted_origins'],
            $booking['create_body_max_bytes'],
            new PdoAuditLog($pdo),
        );
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['CONTENT-LENGTH'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $controller->create(
            (string) file_get_contents('php://input'),
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        )->send();
    } catch (Throwable) {
        (new App\Http\BookingApiResponse(
            ['error' => 'A foglalási kérés átmenetileg nem dolgozható fel.'],
            503,
        ))->send();
    }
});

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
