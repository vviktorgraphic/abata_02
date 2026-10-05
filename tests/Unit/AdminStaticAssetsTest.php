<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controller\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class AdminStaticAssetsTest extends TestCase
{
    public function testAllMappedAssetsHaveMatchingPhysicalFingerprints(): void
    {
        $root = dirname(__DIR__, 2);
        $assets = require $root . '/config/static-assets.php';
        foreach (['booking_css' => '/static/css/booking.css', 'booking_js' => '/static/js/booking-calendar.js',
            'admin_css' => '/static/css/admin.css', 'admin_js' => '/static/js/admin-auth.js'] as $key => $canonical) {
            $extension = pathinfo($canonical, PATHINFO_EXTENSION);
            $stem = substr($canonical, 0, -strlen($extension) - 1);
            self::assertMatchesRegularExpression('~^' . preg_quote($stem, '~') . '\.[0-9a-f]{12}\.' . $extension . '$~', $assets[$key]);
            $physical = $root . '/public' . $assets[$key];
            self::assertFileExists($physical);
            $hash = hash_file('sha256', $physical);
            self::assertStringContainsString('.' . substr($hash, 0, 12) . '.', $assets[$key]);
            self::assertSame(hash_file('sha256', $root . '/public' . $canonical), $hash);
        }
    }

    public function testAdminLayoutRendersMappedEscapedPaths(): void
    {
        $root = dirname(__DIR__, 2);
        $assets = require $root . '/config/static-assets.php';
        $html = (new AdminView($root . '/templates'))->render('_layout_start');
        self::assertStringContainsString('href="' . $assets['admin_css'] . '"', $html);
        self::assertStringContainsString('src="' . $assets['admin_js'] . '"', $html);
        self::assertStringNotContainsString('href="/static/css/admin.css"', $html);
        self::assertStringNotContainsString('src="/static/js/admin-auth.js"', $html);
        $source = file_get_contents($root . '/templates/admin/_layout_start.php');
        foreach (['admin_css', 'admin_js'] as $key) {
            self::assertStringContainsString("htmlspecialchars(\$staticAssets['" . $key . "'], ENT_QUOTES | ENT_SUBSTITUTE", $source);
        }
    }

    public function testReleaseToolsCoverAllFourRequiredAssets(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Update-StaticAssetFingerprints.ps1', 'Verify-ReleasePackage.ps1'] as $file) {
            $source = file_get_contents($root . '/tools/' . $file);
            foreach (['booking_css', 'booking_js', 'admin_css', 'admin_js'] as $key) {
                self::assertStringContainsString($key . ' = ', $source);
            }
            self::assertStringContainsString('Get-FileHash', $source);
        }
        $package = file_get_contents($root . '/tools/New-ReleasePackage.ps1');
        self::assertStringContainsString('archive --format=tar', $package);
    }
}
