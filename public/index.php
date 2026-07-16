<?php

declare(strict_types=1);

use App\Http\Controller\HomeController;
use App\Http\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Europe/Budapest');

$router = new Router();
$controller = new HomeController();

$router->get('/', [$controller, 'index']);
$router->get('/health', [$controller, 'health']);
$router->get('/admin/login', [$controller, 'adminLogin']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
