<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$environment = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production');
if (!in_array($environment, ['development', 'testing', 'local'], true)) {
    fwrite(STDERR, "Demo data can only be seeded in a development or testing environment.\n");
    exit(1);
}

$pdo = ConnectionFactory::create(require $root . '/config/database.php');
$timezone = new DateTimeZone('Europe/Budapest');
$today = new DateTimeImmutable('today', $timezone);
$date = static fn (int $days): string => $today->modify(sprintf('+%d days', $days))->format('Y-m-d');

$bookings = [
    ['DEMO-CONFIRMED', 'confirmed', 7, 11],
    ['DEMO-TURNOVER-A', 'confirmed', 15, 18],
    ['DEMO-TURNOVER-B', 'confirmed', 18, 22],
    ['DEMO-PENDING', 'pending', 24, 27],
    ['DEMO-CANCELLED', 'cancelled', 29, 32],
];

$bookingStatement = $pdo->prepare(
    'INSERT INTO bookings
        (reference, status, arrival_date, departure_date, guest_name, guest_email, adults, children)
     VALUES
        (:reference, :status, :arrival, :departure, :guest_name, :guest_email, 2, 0)
     ON DUPLICATE KEY UPDATE
        status = VALUES(status), arrival_date = VALUES(arrival_date), departure_date = VALUES(departure_date)'
);
$deleteBlock = $pdo->prepare('DELETE FROM blocked_periods WHERE reason = :reason');
$insertBlock = $pdo->prepare(
    'INSERT INTO blocked_periods (start_date, end_date, reason) VALUES (:start_date, :end_date, :reason)'
);
$deleteDemoPrice = $pdo->prepare('DELETE FROM pricing_rules WHERE name = :name');
$insertDemoPrice = $pdo->prepare(
    'INSERT INTO pricing_rules
        (name, valid_from, valid_until, nightly_price, base_unit, currency, minimum_nights, priority, is_active)
     VALUES
        (:name, :valid_from, :valid_until, :nightly_price, :base_unit, :currency, 1, 0, 1)'
);

$pdo->beginTransaction();
try {
    foreach ($bookings as [$reference, $status, $arrivalOffset, $departureOffset]) {
        $bookingStatement->execute([
            'reference' => $reference,
            'status' => $status,
            'arrival' => $date($arrivalOffset),
            'departure' => $date($departureOffset),
            'guest_name' => 'Demo Guest',
            'guest_email' => 'demo@example.invalid',
        ]);
    }

    $reason = 'DEMO-BLOCKED-PERIOD';
    $deleteBlock->execute(['reason' => $reason]);
    $insertBlock->execute(['start_date' => $date(35), 'end_date' => $date(38), 'reason' => $reason]);

    $priceName = 'DEMO ONLY - illustrative person/night price';
    $deleteDemoPrice->execute(['name' => $priceName]);
    $insertDemoPrice->execute([
        'name' => $priceName,
        'valid_from' => $date(0),
        'valid_until' => $date(730),
        'nightly_price' => '10000.00',
        'base_unit' => 'per_person_per_night',
        'currency' => 'HUF',
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}

echo "Demo availability and illustrative non-production pricing data seeded. Existing demo records were updated.\n";

