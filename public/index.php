<?php

declare(strict_types=1);

use App\Http\Controller\HomeController;
use App\Http\Router;
use App\Application\Availability\GetAvailabilityHandler;
use App\Http\Controller\AvailabilityController;
use App\Http\Controller\BookingValidationController;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoBlockedPeriodReadRepository;
use App\Infrastructure\Persistence\PdoBookingReadRepository;

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

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
