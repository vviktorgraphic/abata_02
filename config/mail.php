<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'production';
$production = $environment === 'production';
$value = static function (string $name, ?string $developmentDefault = null) use ($production): string {
    $configured = getenv($name);
    if ($configured !== false && trim($configured) !== '') {
        return trim($configured);
    }
    if (!$production && $developmentDefault !== null) {
        return $developmentDefault;
    }
    throw new RuntimeException($name . ' is required' . ($production ? ' in production.' : '.'));
};

$host = $value('MAIL_HOST', 'mailpit');
$portValue = $value('MAIL_PORT', '1025');
if (filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
    throw new RuntimeException('MAIL_PORT must be an integer between 1 and 65535.');
}
$timeoutValue = $value('MAIL_TIMEOUT_SECONDS', '10');
if (filter_var($timeoutValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]) === false) {
    throw new RuntimeException('MAIL_TIMEOUT_SECONDS must be an integer between 1 and 300.');
}
$encryption = strtolower($value('MAIL_ENCRYPTION', 'none'));
$username = $production ? $value('MAIL_USERNAME') : (getenv('MAIL_USERNAME') === false ? '' : trim((string) getenv('MAIL_USERNAME')));
$password = $production ? $value('MAIL_PASSWORD') : (getenv('MAIL_PASSWORD') === false ? '' : (string) getenv('MAIL_PASSWORD'));
$fromEmail = $value('MAIL_FROM_EMAIL', 'no-reply@abata.local');
$fromName = $value('MAIL_FROM_NAME', 'A Bata');
$guestReplyToEmail = $value('GUEST_REPLY_TO_EMAIL', 'info@abata.local');
$guestReplyToName = $value('GUEST_REPLY_TO_NAME', 'A Bata');

if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
    throw new RuntimeException('MAIL_ENCRYPTION must be none, tls or ssl.');
}
if ($production && !in_array($encryption, ['tls', 'ssl'], true)) {
    throw new RuntimeException('MAIL_ENCRYPTION must be tls or ssl in production.');
}
if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $fromEmail) === 1) {
    throw new RuntimeException('MAIL_FROM_EMAIL must be a valid single e-mail address.');
}
if (preg_match('/[\r\n]/', $fromName) === 1 || mb_strlen($fromName) > 120) {
    throw new RuntimeException('MAIL_FROM_NAME is invalid.');
}
if (filter_var($guestReplyToEmail, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $guestReplyToEmail) === 1) {
    throw new RuntimeException('GUEST_REPLY_TO_EMAIL must be a valid single e-mail address.');
}
if (preg_match('/[\r\n]/', $guestReplyToName) === 1 || mb_strlen($guestReplyToName) > 120) {
    throw new RuntimeException('GUEST_REPLY_TO_NAME is invalid.');
}

return [
    'host' => $host,
    'port' => (int) $portValue,
    'timeout_seconds' => (int) $timeoutValue,
    'encryption' => $encryption,
    'username' => $username,
    'password' => $password,
    'from_email' => $fromEmail,
    'from_name' => $fromName,
    'guest_reply_to_email' => $guestReplyToEmail,
    'guest_reply_to_name' => $guestReplyToName,
    'production' => $production,
];

