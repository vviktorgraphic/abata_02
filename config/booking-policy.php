<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'production';
$url = trim(getenv('BOOKING_POLICY_URL') ?: '');
$version = trim(getenv('BOOKING_POLICY_VERSION') ?: '');

if ($url === '') {
    throw new RuntimeException('BOOKING_POLICY_URL is required.');
}
if ($version === '' || mb_strlen($version) > 100 || preg_match('/[\x00-\x1F\x7F]/', $version) === 1) {
    throw new RuntimeException('BOOKING_POLICY_VERSION is required and must be at most 100 characters.');
}

$relative = preg_match('#^/(?!/)[^\x00-\x20\\\\]*$#u', $url) === 1;
$scheme = parse_url($url, PHP_URL_SCHEME);
$https = $scheme === 'https' && filter_var($url, FILTER_VALIDATE_URL) !== false;
$developmentHttp = in_array($environment, ['development', 'local', 'testing'], true)
    && $scheme === 'http'
    && filter_var($url, FILTER_VALIDATE_URL) !== false;

if (!$relative && !$https && !$developmentHttp) {
    throw new RuntimeException('BOOKING_POLICY_URL must be relative or HTTPS; HTTP is allowed only in development.');
}

return ['url' => $url, 'version' => $version];
