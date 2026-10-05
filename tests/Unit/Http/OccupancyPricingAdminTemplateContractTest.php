<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

final class OccupancyPricingAdminTemplateContractTest extends TestCase
{
    public function testBaseBandCreateFormIsAvailableWithoutRemovingExistingActions(): void
    {
        $template = $this->template();

        self::assertStringContainsString('<summary>+ Új ársáv</summary>', $template);
        self::assertStringContainsString('name="action" value="band"', $template);
        self::assertStringContainsString('name="band_id" value="0"', $template);
        self::assertStringContainsString('name="active" value="1"', $template);
        self::assertStringContainsString('name="guest_count"', $template);
        self::assertStringContainsString('name="min_nights"', $template);
        self::assertStringContainsString('name="max_nights"', $template);
        self::assertStringContainsString('name="nightly_price"', $template);

        self::assertStringContainsString('name="band_id" value="<?= $b->id ?>"', $template);
        self::assertStringContainsString('name="action" value="band_toggle"', $template);
        self::assertStringContainsString('<summary>Szerkesztés</summary>', $template);
    }

    public function testDateOverrideCreateFormIsAvailableWithoutRemovingExistingActions(): void
    {
        $template = $this->template();

        self::assertStringContainsString('<summary>+ Új időszak</summary>', $template);
        self::assertStringContainsString('name="action" value="override"', $template);
        self::assertStringContainsString('name="override_id" value="0"', $template);
        self::assertStringContainsString('name="start_date"', $template);
        self::assertStringContainsString('name="end_date"', $template);
        self::assertStringContainsString('name="price_<?= $i ?>"', $template);

        self::assertStringContainsString('name="override_id" value="<?= $o->id ?>"', $template);
        self::assertStringContainsString('name="action" value="override_toggle"', $template);
    }

    public function testFormsCarryCsrfAndOptimisticVersionFields(): void
    {
        $template = $this->template();

        self::assertGreaterThanOrEqual(6, substr_count($template, 'name="_csrf"'));
        self::assertGreaterThanOrEqual(6, substr_count($template, 'name="version"'));
    }

    private function template(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/templates/admin/occupancy-pricing.php');
    }
}
