<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\HomeController;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class StaticAssetPathContractTest extends TestCase
{
    public function testPublicBookingHtmlUsesStaticCssAndCalendarScript(): void
    {
        ob_start();
        (new HomeController(dirname(__DIR__, 3) . '/templates', '/foglalasi-szabalyzat', '/privacy'))->index();
        $html = (string) ob_get_clean();

        self::assertMatchesRegularExpression('#href="/static/css/booking\.[0-9a-f]{12}\.css"#', $html);
        self::assertMatchesRegularExpression('#src="/static/js/booking-calendar\.[0-9a-f]{12}\.js"#', $html);
        self::assertStringNotContainsString('/assets/', $html);
    }

    public function testAdminLoginAndLegacyImportHtmlUseSharedStaticAssets(): void
    {
        $root = dirname(__DIR__, 3);
        $render = static function (string $template) use ($root): string {
            $title = 'Teszt';
            $csrfToken = 'test-token';
            $preview = null;
            $error = null;
            ob_start();
            require $root . '/templates/admin/' . $template;
            return (string) ob_get_clean();
        };

        foreach (['login.php', 'legacy-booking-import.php'] as $template) {
            $html = $render($template);
            self::assertMatchesRegularExpression('#href="/static/css/admin\.[0-9a-f]{12}\.css"#', $html, $template);
            self::assertMatchesRegularExpression('#src="/static/js/admin-auth\.[0-9a-f]{12}\.js"#', $html, $template);
            self::assertStringNotContainsString('/assets/', $html, $template);
        }
    }

    public function testRuntimeTemplatesContainNoAssetsUrl(): void
    {
        $root = dirname(__DIR__, 3);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            self::assertStringNotContainsString('/assets/', (string) file_get_contents($path), $path);
        }
    }
}
