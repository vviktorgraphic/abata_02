<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\UnexpectedHttpErrorResponse;
use PHPUnit\Framework\TestCase;

final class AdminErrorBoundaryTest extends TestCase
{
    public function testFailedTemplateCannotLeakPartialOutputIntoProductionErrorResponse(): void
    {
        $outerLevel = ob_get_level();
        ob_start();
        try {
            (new AdminView(dirname(__DIR__, 2) . '/Fixtures'))->render('broken');
            self::fail('Broken fixture unexpectedly rendered.');
        } catch (\RuntimeException) {
            self::assertSame($outerLevel + 1, ob_get_level());
        }
        $leaked = (string) ob_get_clean();
        self::assertStringNotContainsString('PARTIAL-SENSITIVE-MARKER', $leaked);

        $response = UnexpectedHttpErrorResponse::create();
        self::assertSame(500, $response->status);
        self::assertStringContainsString('Átmeneti hiba', $response->body);
        self::assertStringNotContainsString('RuntimeException', $response->body);
        self::assertStringNotContainsString('stack', $response->body);
        self::assertStringNotContainsString('/var/www', $response->body);
    }
}
