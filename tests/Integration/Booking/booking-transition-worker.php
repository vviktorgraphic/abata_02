<?php

declare(strict_types=1);

use App\Application\Booking\BookingConflict;
use App\Domain\Booking\BookingTransitionNotAllowed;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $reference, $adminId, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (!file_exists($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Barrier timeout.');
        exit(2);
    }
    usleep(1000);
}

$pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
try {
    (new TransactionalBookingRepository($pdo))->transition($reference, 'confirmed', (int) $adminId);
    echo 'CONFIRMED';
} catch (BookingConflict|BookingTransitionNotAllowed) {
    echo 'CONFLICT';
}
