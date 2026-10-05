<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

final class PricingAdminUxContractTest extends TestCase
{
    public function testPricingNavigationAndRoutesLeadOnlyToOwnerWorkflow(): void
    {
        $root = dirname(__DIR__, 3);
        $index = (string) file_get_contents($root . '/public/index.php');
        self::assertStringContainsString("get('/admin/pricing', static fn () => \$admin()['occupancy_pricing']->index()", $index);
        self::assertStringContainsString("post('/admin/pricing', static fn () => \$admin()['occupancy_pricing']->save(", $index);
        self::assertStringNotContainsString("get('/admin/pricing/create'", $index);
        self::assertStringNotContainsString("get('/admin/pricing/{id}/edit'", $index);

        $layout = (string) file_get_contents($root . '/templates/admin/_layout_start.php');
        $labels = ['Foglalások', 'Blokkolt időszakok', 'Árképzés', 'Naptárszinkron'];
        $position = -1;
        foreach ($labels as $label) {
            $next = strpos($layout, '>' . $label . '<');
            self::assertNotFalse($next);
            self::assertGreaterThan($position, $next);
            $position = $next;
        }
    }

    public function testResponsiveCssConstrainsTheDocumentWithoutHidingOverflow(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/static/css/admin.css');
        self::assertStringContainsString('* { box-sizing: border-box; }', $css);
        self::assertStringContainsString('.admin-page { width:100%; max-width:80rem; min-width:0;', $css);
        self::assertStringContainsString('.table-scroll { width:100%; max-width:100%; min-width:0; overflow-x:auto;', $css);
        self::assertStringContainsString('.brand-header nav { display:flex; min-width:0;', $css);
        self::assertStringNotContainsString('overflow-x:hidden', str_replace(' ', '', $css));
        self::assertStringNotContainsString('100vw', $css);
    }
}
