<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;

final class ConfirmWithoutPaymentRequestContractTest extends TestCase
{
    public function testExplicitRoutesAreRegisteredBeforeTheGenericTransitionRoute(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
        $prompt = "'/admin/bookings/{reference}/confirm-without-payment/prompt'";
        $final = "'/admin/bookings/{reference}/confirm-without-payment'";
        $generic = "'/admin/bookings/{reference}/{action}'";

        self::assertStringContainsString($prompt, $routes);
        self::assertStringContainsString($final, $routes);
        self::assertStringContainsString('promptConfirmWithoutPaymentRequest', $routes);
        self::assertStringContainsString('confirmWithoutPaymentRequest', $routes);
        self::assertLessThan(strpos($routes, $generic), strpos($routes, $prompt));
        self::assertLessThan(strpos($routes, $generic), strpos($routes, $final));
    }

    public function testRepositoryExposesAnExplicitCommandInsteadOfAGenericBypassFlag(): void
    {
        $repository = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Infrastructure/Persistence/Booking/TransactionalBookingRepository.php'
        );

        self::assertStringContainsString('public function confirmWithoutPaymentRequest(', $repository);
        self::assertStringNotContainsString('allowBypass', $repository);
        self::assertStringContainsString('booking.confirmed_without_payment_request', $repository);
    }
}
