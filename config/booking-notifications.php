<?php
declare(strict_types=1);
$environment = getenv('APP_ENV') ?: 'production';
$email = trim(getenv('BOOKING_NOTIFICATION_EMAIL') ?: '');
$baseUrl = trim(getenv('BOOKING_ADMIN_BASE_URL') ?: '');
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email) === 1) throw new RuntimeException('BOOKING_NOTIFICATION_EMAIL must be valid.');
if ($baseUrl === '' || preg_match('/[\r\n]/', $baseUrl) === 1 || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) throw new RuntimeException('BOOKING_ADMIN_BASE_URL must be valid.');
if ($environment === 'production' && parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('BOOKING_ADMIN_BASE_URL must use HTTPS in production.');
return ['email' => $email, 'base_url' => rtrim($baseUrl, '/')];
