<?php
declare(strict_types=1);
namespace Tests\Unit\LegacyImport;

use PHPUnit\Framework\TestCase;

final class LegacyImportSafetyContractTest extends TestCase
{
    public function testHistoricalImportDoesNotQueueNotificationsOrFabricatePolicySnapshots(): void
    {
        $service = file_get_contents(dirname(__DIR__, 3) . '/src/Application/LegacyImport/LegacyImportService.php');
        self::assertIsString($service);
        self::assertStringNotContainsString('email_outbox', $service);
        self::assertStringNotContainsString('booking_policy_version', $service);
        self::assertStringNotContainsString('privacy_policy_version', $service);
        self::assertStringContainsString('HUF', $service);
        self::assertStringContainsString('pricing_unavailable', file_get_contents(dirname(__DIR__, 3) . '/database/migrations/021_create_legacy_booking_import_provenance.sql'));
    }
}
