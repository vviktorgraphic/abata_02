<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$assets = [
    'booking_css' => '/static/css/booking.539ed48318b9.css',
    'booking_js' => '/static/js/booking-calendar.0b9c5031b059.js',
];
foreach ($assets as $key => $path) {
    if (!is_file($root . '/public' . $path)) {
        if (($environment = (getenv('APP_ENV') ?: 'production')) === 'production') throw new RuntimeException('Missing fingerprinted static asset: ' . $path);
        $assets[$key] = $key === 'booking_css' ? '/static/css/booking.css' : '/static/js/booking-calendar.js';
    }
}
return $assets;
