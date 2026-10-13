<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoBookingModificationOutbox;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $reference, $modificationId, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (!file_exists($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Barrier timeout.');
        exit(2);
    }
    usleep(1000);
}

$pdo = ConnectionFactory::create(require dirname(__DIR__, 3) . '/config/database.php');
$item = (new PdoBookingModificationOutbox($pdo))->claimForManualDelivery($reference, (int) $modificationId);
echo $item === null ? 'NOT_CLAIMED' : 'CLAIMED';
