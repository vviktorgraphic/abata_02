<?php

declare(strict_types=1);

use App\Application\Mail\BookingManualCommunicationRenderer;
use App\Application\Mail\BookingModificationMailRenderer;
use App\Application\Mail\BookingPaymentRequestMailRenderer;
use App\Application\Mail\BookingRequestMailRenderer;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Application\Mail\BookingStatusMailRenderer;
use App\Application\Mail\EmailOutboxMessageFactory;
use App\Application\Mail\EmailOutboxWorker;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Mail\SmtpConfiguration;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Booking\PdoEmailOutboxWorkerRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Budapest');
$root = dirname(__DIR__);

try {
    require $root . '/vendor/autoload.php';
    App\Bootstrap\EnvironmentBootstrap::load($root);
    $pdo = ConnectionFactory::create(require $root . '/config/database.php');
    $mail = require $root . '/config/mail.php';
    $rendererArguments = [
        $root . '/templates/email',
        $mail['from_email'],
        $mail['from_name'],
        $mail['guest_reply_to_email'],
        $mail['guest_reply_to_name'],
    ];
    $factory = new EmailOutboxMessageFactory(
        new BookingRequestMailRenderer(...$rendererArguments),
        new BookingStatusMailRenderer(...$rendererArguments),
        new BookingPaymentRequestMailRenderer(...$rendererArguments),
        new BookingManualCommunicationRenderer(
            $root . '/templates/email',
            $root . '/resources/email/arrival',
            $mail['from_email'],
            $mail['from_name'],
            $mail['guest_reply_to_email'],
            $mail['guest_reply_to_name'],
        ),
        new BookingModificationMailRenderer(...$rendererArguments),
        new BookingReviewMailRenderer(...$rendererArguments),
    );
    $mailer = new SmtpMailer(new SmtpConfiguration(
        $mail['host'],
        $mail['port'],
        $mail['encryption'],
        $mail['username'] === '' ? null : $mail['username'],
        $mail['password'] === '' ? null : $mail['password'],
        $mail['timeout_seconds'],
        $mail['production'],
    ));
    $result = (new EmailOutboxWorker(
        new PdoEmailOutboxWorkerRepository($pdo),
        $factory,
        $mailer,
        new PdoAuditLog($pdo),
    ))->run();

    fwrite(STDOUT, "Email outbox worker completed.\n");
    foreach ($result as $name => $value) {
        fwrite(STDOUT, $name . '=' . $value . "\n");
    }
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Email outbox worker failed: configuration_or_infrastructure_failure\n");
    exit(1);
}
