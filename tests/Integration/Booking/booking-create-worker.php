<?php

declare(strict_types=1);

use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPricing;
use App\Application\Booking\BookingPricingProvider;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $output, $key, $requestHash, $reference, $arrival, $departure] = $argv;
$pricing = new class implements BookingPricingProvider {
    public function calculate(\PDO $pdo, BookingPersistenceCommand $command): BookingPricing
    {
        return new BookingPricing('30000.00', 'HUF', ['version' => 1, 'total' => 30000, 'currency' => 'HUF']);
    }
};

try {
    $pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
    $result = (new TransactionalBookingRepository($pdo))->create(new BookingPersistenceCommand(
        $key, $requestHash, $reference, $arrival, $departure, 'Concurrency Test Guest',
        'concurrency@example.invalid', '+3612345678', 1, [], null,
        '2040-01-01 12:00:00', 'test-v1', '/booking-policy',
    ), $pricing);
    $payload = ['ok' => true, 'booking_id' => $result->bookingId, 'replayed' => $result->replayed];
} catch (Throwable $error) {
    $payload = ['ok' => false, 'exception' => $error::class];
}

file_put_contents($output, json_encode($payload, JSON_THROW_ON_ERROR));
