<?php

declare(strict_types=1);

$rawEnabled = getenv('BOOKING_LIFECYCLE_ENABLED');
$enabledValue = $rawEnabled === false || trim($rawEnabled) === '' ? 'false' : strtolower(trim($rawEnabled));
if (!in_array($enabledValue, ['true', 'false'], true)) {
    throw new RuntimeException('BOOKING_LIFECYCLE_ENABLED must be exactly true or false.');
}
$enabled = $enabledValue === 'true';

$rawStartDate = getenv('BOOKING_LIFECYCLE_START_DATE');
$startDate = $rawStartDate === false ? '' : trim($rawStartDate);
if ($enabled && $startDate === '') {
    throw new RuntimeException('BOOKING_LIFECYCLE_START_DATE is required when lifecycle automation is enabled.');
}
if ($startDate !== '') {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, new DateTimeZone('Europe/Budapest'));
    if ($parsed === false || $parsed->format('Y-m-d') !== $startDate) {
        throw new RuntimeException('BOOKING_LIFECYCLE_START_DATE must be a valid YYYY-MM-DD date.');
    }
}

return [
    'enabled' => $enabled,
    'start_date' => $startDate === '' ? null : $startDate,
];
