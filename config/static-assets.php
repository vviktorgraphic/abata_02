<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$assets = [
    'booking_css' => '/static/css/booking.a9b92500f481.css',
    'booking_js' => '/static/js/booking-calendar.008ba7834fdd.js',
    'admin_css' => '/static/css/admin.6e9891ae8f01.css',
    'admin_js' => '/static/js/admin-auth.6d4033198ddc.js',
];
foreach ($assets as $key => $path) {
    if (!is_file($root . '/public' . $path)) {
        if (($environment = (getenv('APP_ENV') ?: 'production')) === 'production') throw new RuntimeException('Missing fingerprinted static asset: ' . $path);
        $assets[$key] = preg_replace('/\.[0-9a-f]{12}(?=\.(?:css|js)$)/', '', $path);
    }
}
return $assets;
