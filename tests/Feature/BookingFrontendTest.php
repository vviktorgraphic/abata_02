<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controller\HomeController;
use PHPUnit\Framework\TestCase;

final class BookingFrontendTest extends TestCase
{
    public function testBookingFormTargetsCreateApiWithIdempotencyAndAccessibleStates(): void
    {
        $root = dirname(__DIR__, 2);
        $template = (string) file_get_contents($root . '/templates/booking/index.php');
        $javascript = (string) file_get_contents($root . '/public/assets/js/booking-calendar.js');

        self::assertStringContainsString('Foglalás | A Bata', $template);
        self::assertStringContainsString('name="contact_name"', $template);
        self::assertStringContainsString('name="privacy_accepted"', $template);
        self::assertStringContainsString('htmlspecialchars($privacyPolicyUrl', $template);
        self::assertStringContainsString('/foglalasi-szabalyzat', $template);
        self::assertStringContainsString('name="booking_policy_accepted"', $template);
        self::assertStringContainsString('Foglalási szabályzatot', $template);
        self::assertStringContainsString('aria-live="polite"', $template);
        self::assertStringContainsString("fetch('/api/bookings'", $javascript);
        self::assertStringContainsString('payload.idempotency_key = state.idempotencyKey', $javascript);
        self::assertStringContainsString("payload.booking_policy_accepted = formData.has('booking_policy_accepted')", $javascript);
        self::assertStringContainsString("response.status === 409", $javascript);
        self::assertStringContainsString("result.email_status === 'failed'", $javascript);
        self::assertStringContainsString("submitButton.disabled = true", $javascript);
        self::assertStringContainsString("setAttribute('aria-invalid', 'true')", $javascript);
    }

    public function testConfiguredRelativeAndHttpsPrivacyUrlsAreRenderedFromTheSameControllerInput(): void
    {
        foreach (['/egyedi-adatkezeles', 'https://legal.example.test/privacy?v=2&lang=hu'] as $url) {
            ob_start();
            (new HomeController(dirname(__DIR__, 2) . '/templates', '/foglalasi-szabalyzat', $url))->index();
            $html = (string) ob_get_clean();

            self::assertStringContainsString(
                'href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"',
                $html,
            );
        }
    }
}
