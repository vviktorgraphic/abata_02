<?php

declare(strict_types=1);

use App\Application\Booking\BookingLifecycleWorker;
use App\Application\Mail\BookingReviewMailRenderer;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Mail\SmtpConfiguration;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Booking\PdoBookingLifecycleRepository;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
date_default_timezone_set('Europe/Budapest');
$root=dirname(__DIR__);
try {
    require $root.'/vendor/autoload.php';
    App\Bootstrap\EnvironmentBootstrap::load($root);
    $pdo=ConnectionFactory::create(require $root.'/config/database.php');
    $mail=require $root.'/config/mail.php';
    $smtp=new SmtpMailer(new SmtpConfiguration($mail['host'],$mail['port'],$mail['encryption'],
        $mail['username']===''?null:$mail['username'],$mail['password']===''?null:$mail['password'],$mail['timeout_seconds'],$mail['production']));
    $result=(new BookingLifecycleWorker(new PdoBookingLifecycleRepository($pdo),
        new BookingReviewMailRenderer(
            $root.'/templates/email', $mail['from_email'], $mail['from_name'],
            $mail['guest_reply_to_email'], $mail['guest_reply_to_name'],
        ), $smtp, new PdoAuditLog($pdo)))->run();
    fwrite(STDOUT,json_encode(['event'=>'booking_lifecycle_completed']+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($result['review_failed']===0?0:2);
} catch (Throwable) {
    fwrite(STDERR,"{\"event\":\"booking_lifecycle_failed\",\"error\":\"configuration_or_infrastructure_failure\"}\n");
    exit(1);
}
