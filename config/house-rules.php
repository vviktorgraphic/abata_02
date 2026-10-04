<?php
declare(strict_types=1);
$environment = getenv('APP_ENV') ?: 'production';
$url = trim(getenv('HOUSE_RULES_URL') ?: '');
if ($url === '') throw new RuntimeException('HOUSE_RULES_URL is required.');
$relative = $environment !== 'production' && preg_match('#^/(?!/)[^\x00-\x20\\\\]*$#u', $url) === 1;
$scheme = parse_url($url, PHP_URL_SCHEME);
$https = $scheme === 'https' && filter_var($url, FILTER_VALIDATE_URL) !== false;
$developmentHttp = in_array($environment, ['development', 'local', 'testing'], true) && $scheme === 'http' && filter_var($url, FILTER_VALIDATE_URL) !== false;
if (!$relative && !$https && !$developmentHttp) throw new RuntimeException('HOUSE_RULES_URL must be relative or HTTPS; HTTP is allowed only in development.');
return ['url' => $url];
