<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Infrastructure\Persistence\Booking\PdoAdminMonthlyOccupancyRepository;
use PHPUnit\Framework\TestCase;

final class AdminMonthlyOccupancyRepositoryContractTest extends TestCase
{
    public function test_repository_contract_is_exactly_two_prepared_queries_without_row_queries(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Persistence/Booking/PdoAdminMonthlyOccupancyRepository.php');

        self::assertIsString($source);
        self::assertSame(2, PdoAdminMonthlyOccupancyRepository::QUERY_COUNT);
        self::assertSame(2, substr_count($source, '$this->pdo->prepare('));
        self::assertStringContainsString('LEFT JOIN external_calendar_events', $source);
        self::assertStringContainsString('LEFT JOIN calendar_sources', $source);
    }
}
