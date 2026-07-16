<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class BrandingAuditTest extends TestCase
{
    #[DataProvider('userFacingFiles')]
    public function testUserFacingFilesDoNotContainForbiddenBrandVariants(string $path): void
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);
        self::assertDoesNotMatchRegularExpression('/\b(?:Abata|Abáta|aBata)\b/u', $contents, $path);
    }

    public function testUiStylesAndHtmlEmailDeclareApprovedDesignTokens(): void
    {
        $paths = [
            dirname(__DIR__, 2) . '/public/assets/css/booking.css',
            dirname(__DIR__, 2) . '/public/assets/css/admin.css',
        ];
        $emailTemplates = glob(dirname(__DIR__, 2) . '/templates/email/*.html*');
        self::assertIsArray($emailTemplates);
        self::assertNotEmpty($emailTemplates, 'Legalább egy HTML e-mail sablonnak léteznie kell.');

        foreach ([...$paths, ...$emailTemplates] as $path) {
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString('--color-primary: #19194B;', $contents, $path);
            self::assertStringContainsString('--color-accent: #F0A236;', $contents, $path);
            self::assertStringContainsString('--color-background: #FFFFFF;', $contents, $path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function userFacingFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (['README.md', 'docs', 'public', 'templates'] as $relative) {
            $path = $root . '/' . $relative;
            if (is_file($path)) {
                yield $relative => [$path];
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['md', 'php', 'html', 'txt', 'css', 'js'], true)) {
                    continue;
                }
                yield str_replace('\\', '/', $file->getPathname()) => [$file->getPathname()];
            }
        }
    }
}
