<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Http\Controller\LegalPageController;
use PHPUnit\Framework\TestCase;

final class LegalPagesTest extends TestCase
{
    public function testBothPagesAreBrandedNonIndexableAndPendingApproval(): void
    {
        $controller = new LegalPageController(dirname(__DIR__, 2) . '/templates');
        ob_start(); $controller->bookingPolicy(); $booking = (string) ob_get_clean();
        ob_start(); $controller->privacyPolicy(); $privacy = (string) ob_get_clean();
        foreach ([$booking, $privacy] as $html) {
            self::assertStringContainsString('A Bata', $html);
            self::assertStringContainsString('noindex,nofollow', $html);
            self::assertStringContainsString('jogi jóváhagyásra vár', $html);
            self::assertStringContainsString('production környezetben nem publikálható', $html);
        }
        self::assertStringContainsString('Foglalási szabályzat', $booking);
        self::assertStringContainsString('Adatkezelési tájékoztató', $privacy);
        self::assertStringNotContainsString('<script', $booking);
        self::assertStringNotContainsString('<script', $privacy);
    }

    public function testFrontControllerRegistersBothRoutes(): void
    {
        $front = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertStringContainsString("get('/foglalasi-szabalyzat'", $front);
        self::assertStringContainsString("get('/adatkezelesi_tajekoztato'", $front);
    }
}
