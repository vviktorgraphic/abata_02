<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$assets = [
    'booking_css' => '/static/css/booking.b6b99c0dc8a0.css',
    'booking_js' => '/static/js/booking-calendar.8c622473014c.js',
    'admin_css' => '/static/css/admin.fea988542492.css',
    'admin_js' => '/static/js/admin-auth.67fef7d8207e.js',
];
foreach ($assets as $key => $path) {
    if (!is_file($root . '/public' . $path)) {
        if (($environment = (getenv('APP_ENV') ?: 'production')) === 'production') throw new RuntimeException('Missing fingerprinted static asset: ' . $path);
        $assets[$key] = preg_replace('/\.[0-9a-f]{12}(?=\.(?:css|js)$)/', '', $path);
    }
}
return $assets;
