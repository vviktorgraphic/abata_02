<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

final class AdminUserManagementContractTest extends TestCase
{
    public function testAdminUserRoutesAndSafeFormsArePresent(): void
    {
        $root = dirname(__DIR__, 3);
        $routes = (string) file_get_contents($root . '/public/index.php');
        $controller = (string) file_get_contents($root . '/src/Http/Controller/Admin/AdminUserController.php');
        $form = (string) file_get_contents($root . '/templates/admin/user-create.php');
        self::assertStringContainsString("get('/admin/users'", $routes);
        self::assertStringContainsString("post('/admin/users/{id}/{action}'", $routes);
        self::assertStringContainsString('password_hash($password, PASSWORD_DEFAULT)', $controller);
        self::assertStringContainsString('revokeAllForAdmin', $controller);
        self::assertStringContainsString('invalidateAllForAdmin', $controller);
        self::assertStringContainsString('autocomplete="new-password"', $form);
        self::assertStringNotContainsString('password_hash', $form);
    }
}
