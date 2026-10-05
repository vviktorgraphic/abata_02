<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$assets = [
    'booking_css' => '/static/css/booking.b6b99c0dc8a0.css',
    'booking_js' => '/static/js/booking-calendar.8c622473014c.js',
];
foreach ($assets as $key => $path) {
    if (!is_file($root . '/public' . $path)) {
        if (($environment = (getenv('APP_ENV') ?: 'production')) === 'production') throw new RuntimeException('Missing fingerprinted static asset: ' . $path);
        $assets[$key] = $key === 'booking_css' ? '/static/css/booking.css' : '/static/js/booking-calendar.js';
    }
}
return $assets;
