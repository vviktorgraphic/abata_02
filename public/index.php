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
$router->get('/admin/login', [$controller, 'adminLogin']);
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
